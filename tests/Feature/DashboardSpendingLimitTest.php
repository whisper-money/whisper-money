<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-24 12:00:00');

    $this->user = User::factory()->onboarded()->create(['timezone' => 'UTC']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The catch-all budget the onboarding target creates, with its November
 * period and what it has counted so far.
 */
function spendingLimitBudget(User $user, int $allocated, int $spent, array $state = []): Budget
{
    $budget = Budget::factory()->monthly()->catchAll()->for($user)->create([
        'name' => 'Monthly spending',
        'period_start_day' => 1,
        ...$state,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'allocated_amount' => $allocated,
    ]);

    if ($spent > 0) {
        BudgetTransaction::factory()->create([
            'budget_period_id' => $period->id,
            'amount' => $spent,
        ]);
    }

    return $budget;
}

it('reports the catch-all budget as the spending limit', function () {
    $budget = spendingLimitBudget($this->user, 168400, 164100);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->missing('spendingLimit')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit', [
                    'budget_id' => $budget->id,
                    'allocated' => 168400,
                    'spent' => 164100,
                    'days_left' => 6,
                ])
            )
        );
});

it('reports what was spent past the limit', function () {
    spendingLimitBudget($this->user, 100000, 125000);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit.allocated', 100000)
                ->where('spendingLimit.spent', 125000)
            )
        );
});

it('has no spending limit without a catch-all budget', function () {
    Budget::factory()->monthly()->for($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit', null)
            )
        );
});

it('has no spending limit once the catch-all budget is archived', function () {
    spendingLimitBudget($this->user, 100000, 20000, ['archived_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit', null)
            )
        );
});

it('ignores another user\'s catch-all budget', function () {
    spendingLimitBudget(User::factory()->create(), 100000, 20000);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit', null)
            )
        );
});

it('counts the days left on the owner\'s own clock', function () {
    // 21:00 on 24 November in Buenos Aires is already the 25th in UTC.
    Carbon::setTestNow('2026-11-25 00:30:00');
    $this->user->update(['timezone' => 'America/Argentina/Buenos_Aires']);
    spendingLimitBudget($this->user, 100000, 0);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('spendingLimit.days_left', 6)
                ->where('spendingLimit.spent', 0)
            )
        );
});
