<?php

use App\Enums\AccountType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateSavingsGoal;
use App\Mcp\Tools\ListSavingsGoals;
use App\Mcp\Tools\UpdateSavingsGoal;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a savings goal write tool as $user with a read & write token.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callSavingsGoalTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

function mcpGoalUser(): User
{
    return User::factory()->create(['currency_code' => 'EUR']);
}

function mcpMonthlyGoal(User $user, array $attributes = [], int $target = 30000): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly($target)->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function mcpTag(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings, 'currency_code' => 'EUR'])->id,
        'currency_code' => 'EUR',
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

test('list_savings_goals returns both kinds, monthly ones with every month against its target', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = mcpGoalUser();
    $monthly = mcpMonthlyGoal($user, ['name' => 'Emergency fund']);
    mcpTag($monthly, 32000, '2026-09-12');
    SavingsGoal::factory()->create(['user_id' => $user->id, 'name' => 'House', 'target_amount' => 1_000_000]);

    $this->travelTo(Carbon::parse('2026-10-03'));
    app(SavingsGoalPeriodService::class)->advance($monthly);

    WhisperMoneyServer::actingAs($user)->tool(ListSavingsGoals::class)
        ->assertOk()
        ->assertSee('"currency":"EUR"')
        ->assertSee('"name":"House"')
        ->assertSee('"kind":"one_off"')
        ->assertSee('"target_amount":1000000')
        ->assertSee('"progress":')
        ->assertSee('"name":"Emergency fund"')
        ->assertSee('"kind":"monthly"')
        ->assertSee('"monthly_target_type":"amount"')
        ->assertSee('{"month":"2026-09","target":30000,"saved":32000,"difference":2000,"status":"met","income_base":null}')
        ->assertSee('"months_met":1')
        ->assertSee('"days_left":29');
});

test('list_savings_goals is read-only friendly', function () {
    $user = mcpGoalUser();
    $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

    WhisperMoneyServer::actingAs($user)->tool(ListSavingsGoals::class)
        ->assertOk()
        ->assertSee('"savings_goals":[]');
});

test('create_savings_goal creates a monthly goal with its first month and an auto-tag rule', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = mcpGoalUser();
    $savings = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'name' => 'Rainy day', 'currency_code' => 'EUR']);

    callSavingsGoalTool($user, CreateSavingsGoal::class, [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
        'auto_tag_account_id' => $savings->id,
    ])
        ->assertOk()
        ->assertSee('"kind":"monthly"')
        ->assertSee('"month":"2026-10"');

    $goal = $user->savingsGoals()->sole();

    expect($goal->isMonthly())->toBeTrue()
        ->and($goal->monthly_target_amount)->toBe(30000)
        ->and($goal->periods()->count())->toBe(1)
        ->and(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('create_savings_goal creates a one-off goal', function () {
    $user = mcpGoalUser();

    callSavingsGoalTool($user, CreateSavingsGoal::class, [
        'name' => 'House',
        'kind' => 'one_off',
        'target_amount' => 1_000_000,
        'initial_amount' => 50_000,
    ])->assertOk()->assertSee('"kind":"one_off"')->assertSee('"saved":50000');

    expect($user->savingsGoals()->sole()->target_amount)->toBe(1_000_000);
});

test('create_savings_goal refuses what the web form refuses', function (array $arguments, string $field) {
    $user = mcpGoalUser();
    Label::factory()->create(['user_id' => $user->id, 'name' => 'Taken']);
    $checking = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

    $arguments = array_map(fn (mixed $value): mixed => $value === 'CHECKING' ? $checking->id : $value, $arguments);

    callSavingsGoalTool($user, CreateSavingsGoal::class, $arguments)
        ->assertHasErrors()
        ->assertSee($field);

    expect($user->savingsGoals()->count())->toBe(0);
})->with([
    'no target' => [['name' => 'Fund', 'kind' => 'monthly'], 'monthly target type'],
    'rate over 100' => [['name' => 'Fund', 'kind' => 'monthly', 'monthly_target_type' => 'income_rate', 'monthly_target_rate' => 120], 'monthly target rate'],
    'one-off without total' => [['name' => 'Fund', 'kind' => 'one_off'], 'target amount'],
    'name clash' => [['name' => 'Taken', 'kind' => 'one_off', 'target_amount' => 100], 'already exists'],
    'not a savings account' => [['name' => 'Fund', 'kind' => 'monthly', 'monthly_target_type' => 'amount', 'monthly_target_amount' => 100, 'auto_tag_account_id' => 'CHECKING'], 'savings account'],
]);

test('create_savings_goal needs a write token', function () {
    $user = mcpGoalUser();

    callSavingsGoalTool($user, CreateSavingsGoal::class, ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100], ['mcp:read'])
        ->assertHasErrors()
        ->assertSee('read-only');

    expect($user->savingsGoals()->count())->toBe(0);
});

test('update_savings_goal changes the month in progress and leaves past months alone', function () {
    $this->travelTo(Carbon::parse('2026-09-10'));
    $user = mcpGoalUser();
    $goal = mcpMonthlyGoal($user, ['name' => 'Emergency fund']);

    $this->travelTo(Carbon::parse('2026-10-03'));
    app(SavingsGoalPeriodService::class)->advance($goal);

    callSavingsGoalTool($user, UpdateSavingsGoal::class, [
        'savings_goal_id' => $goal->id,
        'name' => 'Rainy day',
        'monthly_target_type' => 'income_rate',
        'monthly_target_rate' => 15,
        'notify_on_month_end_reminder' => false,
    ])->assertOk()->assertSee('"name":"Rainy day"')->assertSee('"monthly_target_rate":15');

    $goal->refresh();
    $periods = $goal->periods()->orderBy('month')->get();

    expect($goal->label->name)->toBe('Rainy day')
        ->and($goal->notify_on_month_end_reminder)->toBeFalse()
        ->and($periods[0]->target_amount)->toBe(30000)
        ->and($periods[1]->target_type->value)->toBe('income_rate');
});

test('update_savings_goal refuses another user\'s goal, an archived goal and a clashing name', function () {
    $user = mcpGoalUser();
    $other = mcpMonthlyGoal(mcpGoalUser());
    $archived = mcpMonthlyGoal($user, ['name' => 'Old']);
    app(SavingsGoalService::class)->archive($archived);
    $goal = mcpMonthlyGoal($user, ['name' => 'Fund']);
    Label::factory()->create(['user_id' => $user->id, 'name' => 'Taken']);

    callSavingsGoalTool($user, UpdateSavingsGoal::class, ['savings_goal_id' => $other->id, 'name' => 'Mine'])
        ->assertHasErrors()->assertSee('No savings goal with id');
    callSavingsGoalTool($user, UpdateSavingsGoal::class, ['savings_goal_id' => $archived->id, 'name' => 'Back'])
        ->assertHasErrors()->assertSee('archived');
    callSavingsGoalTool($user, UpdateSavingsGoal::class, ['savings_goal_id' => $goal->id, 'name' => 'Taken'])
        ->assertHasErrors()->assertSee('already exists');

    expect($other->fresh()->name)->not->toBe('Mine')
        ->and($goal->fresh()->name)->toBe('Fund');
});

test('update_savings_goal keeps a goal renamed to its own name', function () {
    $user = mcpGoalUser();
    $goal = mcpMonthlyGoal($user, ['name' => 'Fund']);

    callSavingsGoalTool($user, UpdateSavingsGoal::class, ['savings_goal_id' => $goal->id, 'name' => 'Fund'])
        ->assertOk();
});

test('update_savings_goal edits a one-off goal without touching its kind', function () {
    $user = mcpGoalUser();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id, 'target_amount' => 100_000]);

    callSavingsGoalTool($user, UpdateSavingsGoal::class, [
        'savings_goal_id' => $goal->id,
        'target_amount' => 200_000,
        'monthly_target_type' => 'amount',
    ])->assertOk();

    expect($goal->fresh())
        ->target_amount->toBe(200_000)
        ->isMonthly()->toBeFalse();
});
