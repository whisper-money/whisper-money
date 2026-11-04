<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetPeriodService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

use function Pest\Laravel\withHeaders;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the period in progress is the owner's, not the server's.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A monthly carry-over budget with October and November 2026 periods.
 */
function octoberAndNovemberBudget(?string $timezone): Budget
{
    $user = User::factory()->create(['onboarded_at' => now(), 'timezone' => $timezone]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    foreach ([['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30']] as [$start, $end]) {
        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $end,
            'allocated_amount' => 10_000,
            'carried_over_amount' => 0,
        ]);
    }

    return $budget;
}

describe('User::localToday', function () {
    it('reads the date on the user\'s wall clock', function (?string $timezone, string $now, string $expected) {
        Carbon::setTestNow(Carbon::parse($now));

        $today = User::factory()->make(['timezone' => $timezone])->localToday();

        expect($today->toDateTimeString())->toBe("{$expected} 00:00:00")
            ->and($today->timezoneName)->toBe('UTC');
    })->with([
        'west of UTC, still yesterday' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10-31'],
        'UTC' => ['UTC', '2026-11-01 00:30:00', '2026-11-01'],
        'east of UTC, already tomorrow' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11-01'],
        'no timezone falls back to the app' => [null, '2026-11-01 00:30:00', '2026-11-01'],
        'an invalid timezone falls back to the app' => ['Mars/Olympus_Mons', '2026-11-01 00:30:00', '2026-11-01'],
    ]);

    it('only reports a timezone it can parse', function () {
        expect(User::factory()->make(['timezone' => 'Asia/Tokyo'])->validTimezone())->toBe('Asia/Tokyo')
            ->and(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])->validTimezone())->toBeNull()
            ->and(User::factory()->make(['timezone' => null])->validTimezone())->toBeNull()
            ->and(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])->localTimezone())->toBe('UTC');
    });
});

describe('the current period', function () {
    it('is the one covering the owner\'s today', function (?string $timezone, string $now, string $expectedStart) {
        Carbon::setTestNow(Carbon::parse($now));

        $budget = octoberAndNovemberBudget($timezone);

        expect($budget->getCurrentPeriod()->start_date->toDateString())->toBe($expectedStart)
            ->and(Budget::query()->with('currentPeriods')->find($budget->id)->currentPeriods->first()->start_date->toDateString())->toBe($expectedStart);
    })->with([
        'Buenos Aires at 2026-11-01 00:30 UTC' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10-01'],
        'UTC at 2026-11-01 00:30 UTC' => ['UTC', '2026-11-01 00:30:00', '2026-11-01'],
        'no timezone at 2026-11-01 00:30 UTC' => [null, '2026-11-01 00:30:00', '2026-11-01'],
        'Tokyo at 2026-10-31 16:00 UTC' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11-01'],
        'UTC at 2026-10-31 16:00 UTC' => ['UTC', '2026-10-31 16:00:00', '2026-10-01'],
    ]);

    it('is eager loaded per owner when one list mixes timezones', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $buenosAires = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');
        $utc = octoberAndNovemberBudget('UTC');

        $budgets = Budget::query()->with('currentPeriods')->get()->keyBy('id');

        expect($budgets[$buenosAires->id]->currentPeriods->pluck('start_date')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($budgets[$utc->id]->currentPeriods->pluck('start_date')->map->toDateString()->all())->toBe(['2026-11-01']);
    });

    it('is the owner\'s on the budgets page', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $budget = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');

        $this->actingAs($budget->user)->get(route('budgets.index'))
            ->assertInertia(fn ($page) => $page->where('budgets.0.periods.0.start_date', '2026-10-01'));

        $this->actingAs($budget->user)->get(route('budgets.show', $budget))
            ->assertInertia(fn ($page) => $page
                ->where('currentPeriod.start_date', '2026-10-01')
                ->where('nextPeriod', null)
                // Loaded to read the owner's today, never sent with the budget.
                ->missing('budget.user'));
    });

    it('is the owner\'s in the public API', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $budget = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');

        withHeaders(apiV1Bearer($budget->user))->getJson('/api/v1/budgets')
            ->assertOk()
            ->assertJsonPath('data.0.current_period.start_date', '2026-10-01');

        withHeaders(apiV1Bearer($budget->user))->getJson("/api/v1/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('data.current_period.start_date', '2026-10-01');
    });

    it('starts a new budget on the owner\'s today', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $user = User::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => 1,
        ]);

        $period = app(BudgetPeriodService::class)->generatePeriod($budget);

        expect($period->start_date->toDateString())->toBe('2026-10-01');
    });
});

describe('budgets:generate-periods', function () {
    it('closes a period once it is over for the owner', function (?string $timezone, string $now, bool $octoberClosed) {
        Carbon::setTestNow(Carbon::parse($now));

        $budget = octoberAndNovemberBudget($timezone);

        $this->artisan('budgets:generate-periods')->assertSuccessful();

        $november = $budget->periods()->where('start_date', '2026-11-01')->first();

        // Nothing was spent in October, so closing it carries the whole 10,000 over.
        expect($november->carried_over_amount)->toBe($octoberClosed ? 10_000 : 0);
    })->with([
        'Buenos Aires at 2026-11-01 00:30 UTC: still October' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', false],
        'Buenos Aires at 2026-11-01 03:30 UTC: November' => ['America/Argentina/Buenos_Aires', '2026-11-01 03:30:00', true],
        'UTC at 2026-11-01 00:30 UTC' => ['UTC', '2026-11-01 00:30:00', true],
        'no timezone at 2026-11-01 00:30 UTC' => [null, '2026-11-01 00:30:00', true],
        'Tokyo at 2026-10-31 16:00 UTC: already November' => ['Asia/Tokyo', '2026-10-31 16:00:00', true],
        'UTC at 2026-10-31 16:00 UTC' => ['UTC', '2026-10-31 16:00:00', false],
    ]);

    it('keeps the chain ahead of the owner\'s today', function () {
        // Already 1 November in Tokyo: November is current, so December and
        // January are the two periods ahead.
        Carbon::setTestNow(Carbon::parse('2026-10-31 16:00:00'));

        $budget = octoberAndNovemberBudget('Asia/Tokyo');

        $this->artisan('budgets:generate-periods')->assertSuccessful();
        $this->artisan('budgets:generate-periods')->assertSuccessful();

        expect($budget->periods()->orderBy('start_date')->pluck('start_date')->map->toDateString()->all())
            ->toBe(['2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01']);
    });

    it('runs every hour, since periods end at each owner\'s midnight', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'budgets:generate-periods'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(60);
    });
});

it('refuses existence queries on currentPeriods, which cannot know each owner\'s today', function () {
    Budget::query()->whereHas('currentPeriods')->get();
})->throws(LogicException::class);
