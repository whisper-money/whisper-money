<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
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

test('the planning page splits one read of the goals into both kinds, each in its own order', function () {
    $user = monthlyPagesUser();
    $newer = monthlyPagesGoal($user, ['name' => 'A newer', 'position' => 0, 'created_at' => '2026-09-20']);
    $older = monthlyPagesGoal($user, ['name' => 'B older', 'position' => 1, 'created_at' => '2026-08-15']);
    $second = SavingsGoal::factory()->create(['user_id' => $user->id, 'name' => 'House', 'position' => 2]);
    $first = SavingsGoal::factory()->create(['user_id' => $user->id, 'name' => 'Car', 'position' => 1]);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->where('savingsGoals.0.id', $first->id)
            ->where('savingsGoals.1.id', $second->id)
            ->has('savingsGoals.0.stats')
            ->where('monthlySavingsGoals.0.id', $older->id)
            ->where('monthlySavingsGoals.1.id', $newer->id)
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

test('an archived monthly goal page has no month in progress and its archive month reads archived', function () {
    $user = monthlyPagesUser();
    $goal = monthlyPagesGoal($user, ['created_at' => '2026-08-15']);
    app(SavingsGoalService::class)->archive($goal);

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page
            ->whereNot('savingsGoal.archived_at', null)
            ->where('monthly.current', null)
            ->where('monthly.history.2.month', '2026-10')
            ->where('monthly.history.2.status', 'archived')
        );
});

test('the planning page sends the auto-tag accounts only when the dialog asks, from the active space', function () {
    $user = monthlyPagesUser();
    $savings = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'name' => 'Rainy day']);
    Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);
    Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'space_id' => Space::factory()->create(['owner_id' => $user->id])->id]);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page->missing('autoTagAccounts'));

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->reloadOnly('autoTagAccounts', fn ($reload) => $reload
                ->has('autoTagAccounts', 1)
                ->where('autoTagAccounts.0.id', $savings->id)
                ->where('autoTagAccounts.0.name', 'Rainy day')
                ->where('autoTagAccounts.0.used_by', null)
            )
        );
});

test('goal payloads carry no user or raw periods', function () {
    $user = monthlyPagesUser();
    $goal = monthlyPagesGoal($user);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->missing('monthlySavingsGoals.0.user')
            ->missing('monthlySavingsGoals.0.periods')
        );

    $this->actingAs($user)->get("/savings-goals/{$goal->id}")
        ->assertInertia(fn ($page) => $page
            ->missing('savingsGoal.user')
            ->missing('savingsGoal.periods')
        );
});

test('linking transactions persists the month a read only showed', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = monthlyPagesUser();
    $goal = monthlyPagesGoal($user);

    $this->travelTo(Carbon::parse('2026-10-01 09:00'));
    $this->actingAs($user)->put("/savings-goals/{$goal->id}/transactions", ['transaction_ids' => []]);

    expect($goal->periods()->orderBy('month')->pluck('month')->map->format('Y-m')->all())->toBe(['2026-09', '2026-10']);
});

test('the auto-tag accounts say which running goal already uses each one', function () {
    $user = monthlyPagesUser();
    $savings = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings]);
    monthlyPagesGoal($user, ['name' => 'Emergency fund', 'auto_tag_account_id' => $savings->id]);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->reloadOnly('autoTagAccounts', fn ($reload) => $reload
                ->where('autoTagAccounts.0.used_by', 'Emergency fund')
            )
        );
});
