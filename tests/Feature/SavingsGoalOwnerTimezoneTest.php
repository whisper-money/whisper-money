<?php

use App\Enums\AccountType;
use App\Enums\SavingsGoalMonthStatus;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\MonthlySavingsGoalClosed;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the month in progress is the owner's, not the server's.
 */

beforeEach(fn () => Mail::fake());

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A monthly goal of a user in $timezone, created on $createdAt with its
 * months up to then open.
 */
function timezoneGoal(?string $timezone, string $createdAt = '2026-10-05 12:00:00'): SavingsGoal
{
    Carbon::setTestNow(Carbon::parse($createdAt));
    $user = User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR', 'timezone' => $timezone]);
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal->fresh();
}

function timezoneSave(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings])->id,
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

describe('the month in progress', function () {
    it('is the owner\'s month', function (?string $timezone, string $now, string $expected, int $daysLeft) {
        $goal = timezoneGoal($timezone);
        Carbon::setTestNow(Carbon::parse($now));

        $stats = app(MonthlySavingsGoalStats::class)->forGoal($goal);

        expect($stats['current']['month'])->toBe($expected)
            ->and($stats['current']['days_left'])->toBe($daysLeft);
    })->with([
        'Buenos Aires, still 31 October' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10', 1],
        'UTC, already November' => ['UTC', '2026-11-01 00:30:00', '2026-11', 30],
        'Tokyo, already November' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11', 30],
        'UTC, still October' => ['UTC', '2026-10-31 16:00:00', '2026-10', 1],
        'no timezone falls back to the app' => [null, '2026-11-01 00:30:00', '2026-11', 30],
    ]);

    it('keeps October unjudged until it is over for the owner', function () {
        $goal = timezoneGoal('America/Argentina/Buenos_Aires');
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $history = app(MonthlySavingsGoalStats::class)->forGoal($goal)['history'];

        expect(array_column($history, 'month'))->toBe(['2026-10'])
            ->and($history[0]['status'])->toBe(SavingsGoalMonthStatus::InProgress);
    });

    it('opens a goal created on the owner\'s last evening of the month in that month, as a partial one', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 01:00:00'));
        $user = User::factory()->create(['onboarded_at' => now(), 'timezone' => 'America/Argentina/Buenos_Aires']);

        $goal = app(SavingsGoalService::class)->createFromInput($user, [
            'kind' => 'monthly',
            'name' => 'Emergency fund',
            'monthly_target_type' => 'amount',
            'monthly_target_amount' => 30000,
        ]);

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($goal->createdMonth()->toDateString())->toBe('2026-10-01')
            ->and($goal->isPartialMonth(Carbon::parse('2026-10-01')))->toBeTrue();

        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00'));
        $history = app(MonthlySavingsGoalStats::class)->forGoal($goal->fresh())['history'];

        expect(array_column($history, 'month'))->toBe(['2026-10', '2026-11'])
            ->and($history[0]['status'])->toBe(SavingsGoalMonthStatus::Partial);
    });
});

describe('the budgets page', function () {
    it('counts last month on the owner\'s calendar', function (?string $timezone, string $expected) {
        $goal = timezoneGoal($timezone, '2026-09-05 12:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $this->actingAs($goal->user)->get('/budgets')
            ->assertInertia(fn ($page) => $page->where('monthlySavingsLastMonth.month', $expected));
    })->with([
        'Buenos Aires' => ['America/Argentina/Buenos_Aires', '2026-09'],
        'UTC' => ['UTC', '2026-10'],
    ]);
});

describe('savings-goals:generate-periods', function () {
    it('closes October only once October is over for the owner', function () {
        $goal = timezoneGoal('America/Argentina/Buenos_Aires');
        timezoneSave($goal, 31000, '2026-10-20');

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($goal->periods()->sole()->closed_at)->toBeNull()
            ->and($goal->user->notifications()->count())->toBe(0);

        Carbon::setTestNow(Carbon::parse('2026-11-01 03:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        $october = $goal->periods()->where('month', '2026-10-01')->sole();

        expect($october->closed_at)->not->toBeNull()
            ->and($goal->periods()->where('month', '2026-11-01')->exists())->toBeTrue()
            ->and($goal->user->notifications()->count())->toBe(1)
            ->and($goal->user->notifications()->first()->type)->toBe(MonthlySavingsGoalClosed::class)
            ->and($goal->user->notifications()->first()->data['month'])->toBe('2026-10');
    });

    it('closes October at the same instant for a UTC owner', function (?string $timezone) {
        $goal = timezoneGoal($timezone);

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->where('month', '2026-10-01')->sole()->closed_at)->not->toBeNull();
    })->with(['UTC' => 'UTC', 'no timezone' => null]);

    it('closes October for a Tokyo owner while it is still October in UTC', function () {
        $goal = timezoneGoal('Asia/Tokyo');

        Carbon::setTestNow(Carbon::parse('2026-10-31 16:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->where('month', '2026-10-01')->sole()->closed_at)->not->toBeNull()
            ->and($goal->periods()->where('month', '2026-11-01')->exists())->toBeTrue();
    });

    it('sends the month-end reminder at 07:00 on the owner\'s clock, once', function () {
        timezoneGoal('America/Argentina/Buenos_Aires');

        // 07:00 UTC on 28 October is 04:00 in Buenos Aires.
        Carbon::setTestNow(Carbon::parse('2026-10-28 07:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertNothingOutgoing();

        foreach (['10:00', '11:00', '23:00'] as $hour) {
            Carbon::setTestNow(Carbon::parse("2026-10-28 {$hour}:00"));
            $this->artisan('savings-goals:generate-periods');
        }

        Mail::assertQueuedCount(1);
    });

    it('sends the reminder on the owner\'s first day of the window, even if UTC has not reached it', function () {
        timezoneGoal('Asia/Tokyo');

        // 22:00 UTC on 26 October is 07:00 on 27 October in Tokyo, the first
        // of the last five days.
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:00:00'));
        $this->artisan('savings-goals:generate-periods');

        Mail::assertQueuedCount(1);
    });

    it('keeps the 07:00 UTC reminder for a user without a timezone', function () {
        timezoneGoal(null);

        Carbon::setTestNow(Carbon::parse('2026-10-28 06:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertNothingOutgoing();

        Carbon::setTestNow(Carbon::parse('2026-10-28 07:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertQueuedCount(1);
    });

    it('is scheduled hourly with a lock that expires within the hour', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'savings-goals:generate-periods'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(60);
    });
});
