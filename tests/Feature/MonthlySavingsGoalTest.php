<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalKind;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Support\Carbon;

function monthlyGoalUser(): User
{
    return User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR']);
}

function monthlyGoalAccount(User $user, AccountType $type = AccountType::Savings, string $name = 'Savings'): Account
{
    return Account::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'name' => $name,
        'currency_code' => 'EUR',
    ]);
}

function monthlyGoalIncome(User $user, int $amount, string $date): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => monthlyGoalAccount($user, AccountType::Checking, 'Checking')->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income])->id,
        'amount' => $amount,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ]);
}

/**
 * A transfer into a savings account tagged with the goal's label: its amount
 * counts as it stands.
 */
function monthlyGoalContribution(SavingsGoal $goal, int $amount, string $date): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => monthlyGoalAccount($goal->user)->id,
        'amount' => $amount,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ]);
    $transaction->labels()->attach($goal->label_id);

    return $transaction;
}

function monthlyGoalStats(SavingsGoal $goal): array
{
    return app(MonthlySavingsGoalStats::class)->forGoal($goal->fresh());
}

function createMonthlyGoal(User $user, array $attributes = []): SavingsGoal
{
    test()->actingAs($user)->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
        ...$attributes,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return SavingsGoal::query()->where('user_id', $user->id)->latest()->firstOrFail();
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-10 12:00:00')));

test('creating a monthly goal stores its target and opens the current month', function () {
    $user = monthlyGoalUser();

    $goal = createMonthlyGoal($user);

    expect($goal->kind)->toBe(SavingsGoalKind::Monthly)
        ->and($goal->monthly_target_type)->toBe(MonthlyTargetType::Amount)
        ->and($goal->monthly_target_amount)->toBe(30000)
        ->and($goal->target_amount)->toBe(0)
        ->and($goal->notify_on_month_end_reminder)->toBeTrue()
        ->and($goal->label_id)->not->toBeNull()
        ->and($goal->periods()->count())->toBe(1);

    $period = $goal->periods()->first();
    expect($period->monthKey())->toBe('2026-10')
        ->and($period->resolved_target_amount)->toBe(30000)
        ->and($period->closed_at)->toBeNull();
});

test('a one-off goal is still created without any months', function () {
    $user = monthlyGoalUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'New car',
        'target_amount' => 500000,
    ])->assertRedirect();

    $goal = SavingsGoal::query()->where('user_id', $user->id)->firstOrFail();

    expect($goal->kind)->toBe(SavingsGoalKind::OneOff)
        ->and($goal->periods()->count())->toBe(0);
});

test('a monthly goal needs the value its target type uses', function (array $input, string $error) {
    $this->actingAs(monthlyGoalUser())->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        ...$input,
    ])->assertSessionHasErrors($error);
})->with([
    'amount without amount' => [['monthly_target_type' => 'amount'], 'monthly_target_amount'],
    'rate without rate' => [['monthly_target_type' => 'income_rate'], 'monthly_target_rate'],
    'rate above 100' => [['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 120], 'monthly_target_rate'],
    'no type' => [['monthly_target_amount' => 30000], 'monthly_target_type'],
]);

test('a share-of-income target is the average income of the previous three complete months', function () {
    $user = monthlyGoalUser();
    monthlyGoalIncome($user, 900000, '2026-06-15'); // four months back: outside the base
    monthlyGoalIncome($user, 300000, '2026-07-31');
    monthlyGoalIncome($user, 330000, '2026-08-31');
    monthlyGoalIncome($user, 360000, '2026-09-30');
    monthlyGoalIncome($user, 999999, '2026-10-01'); // the month being opened: ignored

    $goal = createMonthlyGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 20]);

    $period = $goal->periods()->first();
    expect($period->resolved_target_amount)->toBe(66000)
        ->and($period->income_base)->toBe(330000)
        ->and($period->target_rate)->toBe(20.0);

    $current = monthlyGoalStats($goal)['current'];
    expect($current['target'])->toBe(66000)
        ->and($current['income_base'])->toBe(330000)
        ->and($current['is_live_target'])->toBeFalse();
});

test('a share-of-income target averages fewer months when that is all the history there is', function () {
    $user = monthlyGoalUser();
    monthlyGoalIncome($user, 200000, '2026-09-05');

    $goal = createMonthlyGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 10]);

    expect($goal->periods()->first()->resolved_target_amount)->toBe(20000);
});

test('without a complete month of income the target follows the month live and freezes at close', function () {
    $user = monthlyGoalUser();
    monthlyGoalIncome($user, 100000, '2026-10-02');

    $goal = createMonthlyGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 50]);

    expect($goal->periods()->first()->resolved_target_amount)->toBeNull();
    expect(monthlyGoalStats($goal)['current'])
        ->target->toBe(50000)
        ->is_live_target->toBeTrue();

    monthlyGoalIncome($user, 100000, '2026-10-30');
    expect(monthlyGoalStats($goal)['current']['target'])->toBe(100000);

    $this->travelTo(Carbon::parse('2026-11-01 03:00:00'));
    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    $october = $goal->periods()->where('month', '2026-10-01')->first();
    expect($october->resolved_target_amount)->toBe(100000)
        ->and($october->closed_at)->not->toBeNull();

    // Income booked into October after it closed no longer moves its target.
    monthlyGoalIncome($user, 500000, '2026-10-31');
    expect(collect(monthlyGoalStats($goal)['history'])->firstWhere('month', '2026-10')['target'])->toBe(100000);
});

test('the daily command opens the current month, closes the past ones and is idempotent', function () {
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user);

    // Three months pass without the command running.
    $this->travelTo(Carbon::parse('2027-01-03 03:00:00'));
    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    $periods = $goal->periods()->orderBy('month')->get();
    expect($periods->map->monthKey()->all())->toBe(['2026-10', '2026-11', '2026-12', '2027-01'])
        ->and($periods->whereNotNull('closed_at')->count())->toBe(3)
        ->and($periods->last()->closed_at)->toBeNull();

    $closedAt = $periods->first()->closed_at;

    $this->travelTo(Carbon::parse('2027-01-03 09:00:00'));
    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    expect($goal->periods()->count())->toBe(4)
        ->and($goal->periods()->orderBy('month')->first()->closed_at->equalTo($closedAt))->toBeTrue();
});

test('the daily command leaves archived and one-off goals alone', function () {
    $user = monthlyGoalUser();
    $archived = SavingsGoal::factory()->monthly()->archived()->create(['user_id' => $user->id]);
    $oneOff = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    expect(SavingsGoalPeriod::query()->whereIn('savings_goal_id', [$archived->id, $oneOff->id])->count())->toBe(0);
});

test('months are judged against their own target, with difference, cumulative and streak', function () {
    $this->travelTo(Carbon::parse('2026-06-10'));
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user, ['monthly_target_amount' => 25000]);

    monthlyGoalContribution($goal, 32000, '2026-06-05'); // met +70
    monthlyGoalContribution($goal, 21000, '2026-07-20'); // missed -40

    // Raised in August: July keeps the 250 it was held to.
    $this->travelTo(Carbon::parse('2026-08-02'));
    $this->artisan('savings-goals:generate-periods');
    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", ['monthly_target_type' => 'amount', 'monthly_target_amount' => 30000])->assertSessionHasNoErrors();

    monthlyGoalContribution($goal, 30000, '2026-08-15'); // met ±0
    monthlyGoalContribution($goal, 45000, '2026-09-01'); // met +150
    monthlyGoalContribution($goal, 12000, '2026-10-06'); // in progress

    $this->travelTo(Carbon::parse('2026-10-10'));
    $this->artisan('savings-goals:generate-periods');

    $stats = monthlyGoalStats($goal);

    expect(collect($stats['history'])->map(fn (array $month): array => [$month['month'], $month['target'], $month['saved'], $month['difference'], $month['status']])->all())->toBe([
        ['2026-06', 25000, 32000, 7000, 'met'],
        ['2026-07', 25000, 21000, -4000, 'missed'],
        ['2026-08', 30000, 30000, 0, 'met'],
        ['2026-09', 30000, 45000, 15000, 'met'],
        ['2026-10', 30000, 12000, -18000, 'in_progress'],
    ])
        ->and($stats['months_met'])->toBe(3)
        ->and($stats['months_closed'])->toBe(4)
        ->and($stats['cumulative_difference'])->toBe(18000)
        ->and($stats['cumulative_saved'])->toBe(128000)
        ->and($stats['cumulative_target'])->toBe(110000)
        ->and($stats['streak'])->toBe(2)
        ->and($stats['best_streak'])->toBe(2)
        ->and($stats['current']['remaining'])->toBe(18000)
        ->and($stats['current']['days_left'])->toBe(22);
});

test('a transaction that arrives late recalculates a closed month', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user);
    monthlyGoalContribution($goal, 10000, '2026-09-03');

    $this->travelTo(Carbon::parse('2026-10-01'));
    $this->artisan('savings-goals:generate-periods');

    $september = fn (): array => collect(monthlyGoalStats($goal)['history'])->firstWhere('month', '2026-09');
    expect($september()['status'])->toBe('missed');

    // A bank sync on 3 October brings in a transfer dated 29 September.
    monthlyGoalContribution($goal, 20000, '2026-09-29');

    expect($september())
        ->saved->toBe(30000)
        ->status->toBe('met')
        ->difference->toBe(0);
});

test('a target of zero counts as met', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 20]);

    $this->travelTo(Carbon::parse('2026-10-02'));
    $this->artisan('savings-goals:generate-periods');

    expect(collect(monthlyGoalStats($goal)['history'])->firstWhere('month', '2026-09'))
        ->target->toBe(0)
        ->status->toBe('met');
});

test('editing the target only changes the month in progress', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user);

    $this->travelTo(Carbon::parse('2026-10-10'));
    $this->artisan('savings-goals:generate-periods');

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", [
        'name' => 'Rainy day',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 45000,
        'notify_on_month_end_reminder' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $goal->refresh();
    expect($goal->monthly_target_amount)->toBe(45000)
        ->and($goal->notify_on_month_end_reminder)->toBeFalse()
        ->and($goal->label->name)->toBe('Rainy day')
        ->and($goal->periods()->where('month', '2026-09-01')->value('resolved_target_amount'))->toBe(30000)
        ->and($goal->periods()->where('month', '2026-10-01')->value('resolved_target_amount'))->toBe(45000);
});

test('a goal cannot switch between one-off and monthly', function (string $factoryState, string $kind) {
    $user = monthlyGoalUser();
    $goal = $factoryState === 'monthly'
        ? SavingsGoal::factory()->monthly()->create(['user_id' => $user->id])
        : SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", ['kind' => $kind, 'name' => 'Renamed'])
        ->assertSessionHasErrors('kind');

    expect($goal->fresh()->kind->value)->toBe($factoryState === 'monthly' ? 'monthly' : 'one_off');
})->with([
    'one-off to monthly' => ['one_off', 'monthly'],
    'monthly to one-off' => ['monthly', 'one_off'],
]);

test('sending the same kind back is not a switch', function () {
    $user = monthlyGoalUser();
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", ['kind' => 'monthly', 'name' => 'Renamed'])
        ->assertSessionHasNoErrors();

    expect($goal->fresh()->name)->toBe('Renamed');
});

test('creating a monthly goal can add a rule that tags transfers into a savings account', function () {
    $user = monthlyGoalUser();
    $savings = monthlyGoalAccount($user, AccountType::Savings, 'ING Savings');
    $other = monthlyGoalAccount($user, AccountType::Checking, 'Checking');
    AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 4]);

    $transferIn = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $savings->id, 'amount' => 10000, 'transaction_date' => '2026-10-01']);
    $withdrawal = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $savings->id, 'amount' => -5000, 'transaction_date' => '2026-10-02']);
    $lastMonth = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $savings->id, 'amount' => 10000, 'transaction_date' => '2026-09-30']);
    $elsewhere = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $other->id, 'amount' => 10000, 'transaction_date' => '2026-10-03']);

    $goal = createMonthlyGoal($user, ['auto_tag_account_id' => $savings->id]);

    $rule = AutomationRule::query()->where('user_id', $user->id)->latest('priority')->firstOrFail();
    expect($rule->priority)->toBe(5)
        ->and($rule->labels->pluck('id')->all())->toBe([$goal->label_id])
        ->and($transferIn->labels()->pluck('labels.id')->all())->toBe([$goal->label_id])
        ->and($withdrawal->labels()->count())->toBe(0)
        ->and($lastMonth->labels()->count())->toBe(0)
        ->and($elsewhere->labels()->count())->toBe(0)
        ->and(monthlyGoalStats($goal)['current']['saved'])->toBe(10000);
});

test('the auto-tag rule only takes the user\'s own savings accounts', function (Closure $account) {
    $user = monthlyGoalUser();

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
        'auto_tag_account_id' => $account($user)->id,
    ])->assertSessionHasErrors('auto_tag_account_id');

    expect(SavingsGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'checking account' => [fn (User $user) => monthlyGoalAccount($user, AccountType::Checking)],
    'someone else\'s savings' => [fn (User $user) => monthlyGoalAccount(monthlyGoalUser())],
]);

test('opening the same month twice returns the existing period', function () {
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => monthlyGoalUser()->id]);
    $service = app(SavingsGoalPeriodService::class);

    $first = $service->openPeriod($goal, Carbon::parse('2026-10-20'));
    $second = $service->openPeriod($goal, Carbon::parse('2026-10-01'));

    expect($second->id)->toBe($first->id)
        ->and(SavingsGoalPeriod::query()->where('savings_goal_id', $goal->id)->count())->toBe(1);
});

test('an amount sent without its target type is rejected, not dropped', function () {
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user);

    $this->actingAs($user)->patch("/savings-goals/{$goal->id}", ['monthly_target_amount' => 45000])
        ->assertSessionHasErrors('monthly_target_type');

    expect($goal->fresh()->monthly_target_amount)->toBe(30000);
});

test('the auto-tag rule also matches the bank, so a same-named account elsewhere is left alone', function () {
    $user = monthlyGoalUser();
    $bank = Bank::factory()->create(['name' => 'ING']);
    $savings = monthlyGoalAccount($user, AccountType::Savings, 'Savings');
    $savings->update(['bank_id' => $bank->id]);
    $namesake = monthlyGoalAccount($user, AccountType::Checking, 'Savings');

    $salary = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $namesake->id, 'amount' => 200000, 'transaction_date' => '2026-10-01']);

    $goal = createMonthlyGoal($user, ['auto_tag_account_id' => $savings->id]);

    $rule = AutomationRule::query()->where('user_id', $user->id)->firstOrFail();
    expect(json_encode($rule->rules_json))->toContain('"bank_name"')
        ->and($salary->labels()->count())->toBe(0)
        ->and($goal->label_id)->not->toBeNull();
});

test('archiving a monthly goal closes its open months and retires its auto-tag rule', function () {
    $user = monthlyGoalUser();
    $savings = monthlyGoalAccount($user);
    $goal = createMonthlyGoal($user, ['auto_tag_account_id' => $savings->id]);
    $shared = AutomationRule::factory()->create(['user_id' => $user->id, 'action_note' => 'keep me']);
    $shared->labels()->attach($goal->label_id);

    $this->actingAs($user)->post("/savings-goals/{$goal->id}/archive")->assertRedirect();

    expect($goal->periods()->whereNull('closed_at')->count())->toBe(0)
        ->and(AutomationRule::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$shared->id])
        ->and($shared->labels()->count())->toBe(0);
});

test('deleting a goal retires its auto-tag rule too', function () {
    $user = monthlyGoalUser();
    $goal = createMonthlyGoal($user, ['auto_tag_account_id' => monthlyGoalAccount($user)->id]);

    $this->actingAs($user)->delete("/savings-goals/{$goal->id}")->assertRedirect();

    expect(AutomationRule::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('monthly goals stay out of the one-off planning list', function () {
    $user = monthlyGoalUser();
    SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);
    $oneOff = SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page
            ->has('savingsGoals', 1)
            ->where('savingsGoals.0.id', $oneOff->id)
        );
});
