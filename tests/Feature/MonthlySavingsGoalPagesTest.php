<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Support\Carbon;

function monthlyPagesUser(): User
{
    return User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR']);
}

function monthlyPagesGoal(User $user, array $attributes = []): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-10 12:00:00')));

test('the planning page lists monthly goals with their months, archived ones included', function () {
    $user = monthlyPagesUser();
    $running = monthlyPagesGoal($user, ['created_at' => '2026-08-15']);
    $archived = SavingsGoal::factory()->monthly()->archived()->create(['user_id' => $user->id]);

    $saving = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings])->id,
        'amount' => 12000,
        'transaction_date' => '2026-10-02',
    ]);
    $saving->labels()->attach($running->label_id);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->has('savingsGoals', 0)
            ->has('monthlySavingsGoals', 2)
            ->where('monthlySavingsGoals.0.id', $running->id)
            ->where('monthlySavingsGoals.0.kind', 'monthly')
            ->has('monthlySavingsGoals.0.monthly.history', 3)
            ->where('monthlySavingsGoals.0.monthly.current.saved', 12000)
            ->where('monthlySavingsGoals.0.monthly.current.target', 30000)
            ->where('monthlySavingsGoals.1.id', $archived->id)
            ->whereNot('monthlySavingsGoals.1.archived_at', null)
        );
});

test('a monthly goal page carries its months instead of the one-off projection', function () {
    $user = monthlyPagesUser();
    $goal = monthlyPagesGoal($user);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page
            ->component('savings-goals/show')
            ->where('stats', null)
            ->where('monthly.current.month', '2026-10')
            ->where('monthly.months_closed', 0)
        );
});

test('a one-off goal page is unchanged', function () {
    $user = monthlyPagesUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id, 'target_amount' => 100000]);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page
            ->where('monthly', null)
            ->where('stats.target', 100000)
        );
});
