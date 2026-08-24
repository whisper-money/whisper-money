<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Jobs\ApplySingleAutomationRuleJob;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ApplyAutomationRule;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleApplier;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a write tool as $user, giving them a real personal access token with the
 * given abilities so the tool's tokenCan('mcp:write') gate is exercised exactly
 * as it is over HTTP.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callWriteTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('creates a manual transaction and defaults the currency to the account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
        'amount' => -450,
        'transaction_date' => '2026-01-15',
    ])->assertOk()->assertSee('Blue Bottle Coffee');

    $transaction = Transaction::query()->where('account_id', $account->id)->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->description)->toBe('Blue Bottle Coffee');
    expect($transaction->currency_code)->toBe('EUR');
    expect($transaction->source->value)->toBe('manually_created');
});

it('creates a transaction on a connected account without touching its balances', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);
    $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Cash withdrawal the bank missed',
        'amount' => -100,
        'transaction_date' => '2026-01-15',
        'update_balance' => true,
    ])->assertOk()->assertSee('Cash withdrawal the bank missed');

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
    expect($account->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
});

it('edits a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Old description',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Fresh description',
    ])->assertOk()->assertSee('Fresh description');

    expect($transaction->fresh()->description)->toBe('Fresh description');
});

it('moves a transaction onto a connected account, unwinding only the manual side', function () {
    $user = User::factory()->create();
    $manualAccount = Account::factory()->create(['user_id' => $user->id]);
    $connectedAccount = Account::factory()->connected()->create(['user_id' => $user->id]);

    $manualAccount->balances()->create(['balance_date' => '2026-01-15', 'balance' => 9_000]);
    $connectedAccount->balances()->create(['balance_date' => '2026-01-15', 'balance' => 50_000]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $manualAccount->id,
        'transaction_date' => '2026-01-15',
        'amount' => -1_000,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'account_id' => $connectedAccount->id,
        'update_balance' => true,
    ])->assertOk();

    expect($transaction->fresh()->account_id)->toBe($connectedAccount->id);
    // The manual account gets the money back; the bank's balance is left alone.
    expect($manualAccount->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
    expect($connectedAccount->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(50_000);
});

it('refuses to edit an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Hacked',
    ])->assertHasErrors(['manually-created']);
});

it('deletes a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertOk();

    expect(Transaction::query()->find($transaction->id))->toBeNull();
});

it('refuses to delete an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertHasErrors(['manually-created']);

    expect(Transaction::query()->find($transaction->id))->not->toBeNull();
});

it('categorizes an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);

    callWriteTool($user, CategorizeTransaction::class, [
        'transaction_id' => $transaction->id,
        'category_id' => $category->id,
    ])->assertOk();

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id);
    expect($transaction->category_source)->toBe(CategorySource::Manual);
});

it('adds a label to an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Reimbursable']);

    callWriteTool($user, LabelTransaction::class, [
        'transaction_id' => $transaction->id,
        'add_label_ids' => [$label->id],
    ])->assertOk()->assertSee('Reimbursable');

    expect($transaction->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('records a balance on a manual account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
        'balance_date' => '2026-01-31',
    ])->assertOk();

    expect($account->balances()->whereDate('balance_date', '2026-01-31')->value('balance'))->toBe(250000);
});

it('refuses to record a balance on a connected account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
    ])->assertHasErrors(['connected']);

    expect($account->balances()->count())->toBe(0);
});

it('creates, updates and deletes a category', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateCategory::class, [
        'name' => 'Travel',
        'icon' => 'Plane',
        'color' => 'blue',
        'type' => 'expense',
    ])->assertOk()->assertSee('Travel');

    $category = $user->categories()->where('name', 'Travel')->firstOrFail();

    callWriteTool($user, UpdateCategory::class, [
        'category_id' => $category->id,
        'name' => 'Holidays',
    ])->assertOk()->assertSee('Holidays');

    expect($category->fresh()->name)->toBe('Holidays');

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $category->id,
    ])->assertOk();

    expect(Category::query()->find($category->id))->toBeNull();
});

it('creates, updates and deletes a label', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Business',
        'color' => 'green',
    ])->assertOk()->assertSee('Business');

    $label = $user->labels()->where('name', 'Business')->firstOrFail();

    callWriteTool($user, UpdateLabel::class, [
        'label_id' => $label->id,
        'name' => 'Work',
    ])->assertOk()->assertSee('Work');

    expect($label->fresh()->name)->toBe('Work');

    callWriteTool($user, DeleteLabel::class, [
        'label_id' => $label->id,
    ])->assertOk();

    expect(Label::query()->find($label->id))->toBeNull();
});

it('creates, updates and deletes an automation rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Grocery rule',
        'priority' => 0,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ])->assertOk()->assertSee('Grocery rule');

    $rule = $user->automationRules()->where('title', 'Grocery rule')->firstOrFail();

    expect($rule->action_category_id)->toBe($category->id);

    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'title' => 'Supermarket rule',
    ])->assertOk()->assertSee('Supermarket rule');

    expect($rule->fresh()->title)->toBe('Supermarket rule');

    callWriteTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => $rule->id,
    ])->assertOk();

    expect(AutomationRule::query()->find($rule->id))->toBeNull();
});

it('requires an automation rule to have at least one action', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'No action',
        'priority' => 0,
        'rules_json' => ['==' => [1, 1]],
    ])->assertHasErrors(['action']);

    expect($user->automationRules()->count())->toBe(0);
});

it('creates a budget with its periods and backfills the transactions already in range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -7_500,
    ]);

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Food Budget',
        'allocated_amount' => 50_000,
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
    ])->assertOk()->assertSee('Food Budget');

    $budget = $user->budgets()->first();

    expect($budget)->not->toBeNull();
    expect($budget->categories->pluck('id')->all())->toBe([$category->id]);
    // The current period and the previous one, for period-over-period comparison.
    expect($budget->periods()->count())->toBe(2);
    expect($budget->getCurrentPeriod()->allocated_amount)->toBe(50_000);
    expect($budget->getCurrentPeriod()->spentAmount())->toBe(7_500);
});

it('creates a catch-all budget without categories, but only one', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Everything Else',
        'allocated_amount' => 100_000,
        'period_type' => 'monthly',
        'rollover_type' => 'carry_over',
        'is_catch_all' => true,
    ])->assertOk();

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Everything Else Again',
        'allocated_amount' => 100_000,
        'period_type' => 'monthly',
        'rollover_type' => 'carry_over',
        'is_catch_all' => true,
    ])->assertHasErrors(['catch-all']);

    expect($user->budgets()->count())->toBe(1);
});

it('refuses a budget that tracks nothing', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Tracks nothing',
        'allocated_amount' => 10_000,
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
    ])->assertHasErrors(['at least one category or label']);

    expect($user->budgets()->count())->toBe(0);
});

it('refuses a budget tracking another user\'s category', function () {
    $user = User::factory()->create();
    $foreignCategory = Category::factory()->create(['user_id' => User::factory()->create()->id]);

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Sneaky',
        'allocated_amount' => 10_000,
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
        'category_ids' => [$foreignCategory->id],
    ])->assertHasErrors(['not categories you own']);

    expect($user->budgets()->count())->toBe(0);
});

it('rejects a period start day that cannot mean anything for the period type', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    // 15 is a valid day of the month but not a day of the week, and the period
    // generator would otherwise walk backwards forever looking for it.
    callWriteTool($user, CreateBudget::class, [
        'name' => 'Weekly',
        'allocated_amount' => 10_000,
        'period_type' => 'weekly',
        'period_start_day' => 15,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
    ])->assertHasErrors(['period start day']);

    expect($user->budgets()->count())->toBe(0);
});

it('edits a budget and moves the new amount onto the period in progress', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
    ]);

    $current = $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    $past = $budget->periods()->create([
        'start_date' => today()->subMonthNoOverflow()->startOfMonth(),
        'end_date' => today()->subMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    $future = $budget->periods()->create([
        'start_date' => today()->addMonthNoOverflow()->startOfMonth(),
        'end_date' => today()->addMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    callWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'name' => 'Groceries Budget',
        'allocated_amount' => 60_000,
    ])->assertOk()->assertSee('Groceries Budget');

    expect($budget->fresh()->name)->toBe('Groceries Budget');
    expect($current->fresh()->allocated_amount)->toBe(60_000);
    expect($future->fresh()->allocated_amount)->toBe(60_000);
    // A period that is already over keeps the amount it was judged against.
    expect($past->fresh()->allocated_amount)->toBe(50_000);
});

it('leaves the fields the agent did not pass alone', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 4,
        'rollover_type' => 'reset',
    ]);

    $period = $budget->periods()->create([
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 50_000,
        'carried_over_amount' => 0,
    ]);

    callWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'name' => 'Renamed',
    ])->assertOk();

    $budget->refresh();

    expect($budget->name)->toBe('Renamed');
    expect($budget->period_start_day)->toBe(4);
    expect($budget->rollover_type->value)->toBe('reset');
    expect($period->fresh()->allocated_amount)->toBe(50_000);
});

it('will not reshape the periods of an existing budget', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => 'monthly',
        'period_start_day' => 1,
    ]);

    // Period settings are fixed once a budget exists — changing them would let
    // the next generated period overlap the current one and count the same
    // transaction twice, so the tool does not accept them at all.
    callWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'period_type' => 'weekly',
        'period_start_day' => 15,
    ])->assertOk();

    $budget->refresh();

    expect($budget->period_type->value)->toBe('monthly');
    expect($budget->period_start_day)->toBe(1);
});

it('refuses to edit another user\'s budget', function () {
    $user = User::factory()->create();
    $foreignBudget = Budget::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Not Yours']);

    callWriteTool($user, UpdateBudget::class, [
        'budget_id' => $foreignBudget->id,
        'name' => 'Hijacked',
    ])->assertHasErrors(['list_budgets']);

    expect($foreignBudget->fresh()->name)->toBe('Not Yours');
});

it('deletes a budget and leaves its transactions alone', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => today(),
        'amount' => -3_000,
    ]);

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Food Budget',
        'allocated_amount' => 50_000,
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
        'category_ids' => [$category->id],
    ])->assertOk();

    $budget = $user->budgets()->firstOrFail();

    callWriteTool($user, DeleteBudget::class, ['budget_id' => $budget->id])->assertOk();

    expect($user->budgets()->count())->toBe(0);
    expect(Transaction::query()->find($transaction->id))->not->toBeNull();
});

it('refuses to delete another user\'s budget', function () {
    $user = User::factory()->create();
    $foreignBudget = Budget::factory()->create(['user_id' => User::factory()->create()->id]);

    callWriteTool($user, DeleteBudget::class, [
        'budget_id' => $foreignBudget->id,
    ])->assertHasErrors(['list_budgets']);

    expect(Budget::query()->find($foreignBudget->id))->not->toBeNull();
});

it('rejects a write tool called with a read-only token', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Should not exist',
        'color' => 'blue',
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    expect($user->labels()->count())->toBe(0);
});

it('still enforces the Pro-plan gate on write tools', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Gated',
        'color' => 'blue',
    ])->assertHasErrors(['Pro']);

    expect($user->labels()->count())->toBe(0);
});

it('never lets a write tool touch another user\'s data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $otherTransaction->id,
    ])->assertHasErrors();

    expect(Transaction::query()->find($otherTransaction->id))->not->toBeNull();
});

it('tells the agent which id was missing and where to find valid ones', function () {
    $user = User::factory()->create();

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => 'no-such-transaction',
        'description' => 'Nope',
    ])->assertHasErrors([
        'No transaction with id no-such-transaction in space '.$user->personalSpace->id.'. Call search_transactions to find ids.',
    ]);

    callWriteTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => 'no-such-rule',
    ])->assertHasErrors([
        'No automation rule with id no-such-rule in space '.$user->personalSpace->id.'. Call list_automation_rules to see valid ids.',
    ]);
});

/**
 * A rule that catches history it never ran on: the matching transactions are
 * created first on purpose, because the TransactionCreated listener only
 * applies rules that already exist — which is exactly the gap
 * apply_automation_rule fills.
 *
 * @return array{0: AutomationRule, 1: Category, 2: Label}
 */
function groceryRuleOverHistory(User $user, int $matchingCount = 2): array
{
    $account = Account::factory()->create(['user_id' => $user->id, 'encrypted' => false]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Essentials']);

    // plaintext(), because rule evaluation reads the description and skips
    // every row the legacy encryption columns still mark as encrypted.
    Transaction::factory()->plaintext()->count($matchingCount)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1_000,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Coffee Shop',
        'amount' => -500,
    ]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    return [$rule, $category, $label];
}

it('previews an automation rule against existing transactions and changes nothing', function () {
    $user = User::factory()->create();
    [$rule] = groceryRuleOverHistory($user);

    callWriteTool($user, ApplyAutomationRule::class, [
        'automation_rule_id' => $rule->id,
    ])->assertOk()
        ->assertSee(['"dry_run":true', '"total_matches":2', 'Grocery Store'])
        ->assertDontSee('Coffee Shop');

    expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $user->id)->has('labels')->count())->toBe(0);
});

it('applies an automation rule to existing transactions once the dry run is turned off', function () {
    $user = User::factory()->create();
    [$rule, $category, $label] = groceryRuleOverHistory($user);

    callWriteTool($user, ApplyAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'dry_run' => false,
    ])->assertOk()->assertSee(['"status":"done"', '"applied":2', '"updated":2']);

    $matched = Transaction::query()->where('description', 'Grocery Store')->with('labels')->get();

    expect($matched)->toHaveCount(2)
        ->and($matched->pluck('category_id')->unique()->all())->toBe([$category->id])
        ->and($matched->filter(fn (Transaction $t): bool => $t->labels->contains($label)))->toHaveCount(2);

    // The transaction the rule does not match is left exactly as it was.
    expect(Transaction::query()->where('description', 'Coffee Shop')->value('category_id'))->toBeNull();
});

it('hands a large apply to the queue instead of running it inline', function () {
    Queue::fake();

    $user = User::factory()->create();
    [$rule] = groceryRuleOverHistory($user, AutomationRuleApplier::SYNC_THRESHOLD + 1);

    callWriteTool($user, ApplyAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'dry_run' => false,
    ])->assertOk()->assertSee(['"status":"queued"', '"total":101']);

    Queue::assertPushed(ApplySingleAutomationRuleJob::class);

    expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0);
});

it('lets a read-only token list automation rules but never apply one', function () {
    $user = User::factory()->create();
    [$rule] = groceryRuleOverHistory($user);

    callWriteTool($user, ApplyAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'dry_run' => false,
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    // The same read-only token still reaches the listing tool.
    WhisperMoneyServer::actingAs($user)
        ->tool(ListAutomationRules::class)
        ->assertOk()
        ->assertSee($rule->title);

    expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0);
});

it('still enforces the Pro-plan gate on apply_automation_rule', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    [$rule] = groceryRuleOverHistory($user);

    callWriteTool($user, ApplyAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'dry_run' => false,
    ])->assertHasErrors(['Pro']);

    expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0);
});

it('moves a subcategory under a different parent and cascades the type down its subtree', function () {
    $user = User::factory()->create();

    $expenses = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $savings = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Savings',
        'type' => CategoryType::Savings,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);

    $child = Category::factory()->childOf($expenses)->create(['user_id' => $user->id, 'name' => 'Rainy day']);
    $grandchild = Category::factory()->childOf($child)->create(['user_id' => $user->id, 'name' => 'Emergency fund']);

    callWriteTool($user, UpdateCategory::class, [
        'category_id' => $child->id,
        'parent_id' => $savings->id,
    ])->assertOk()->assertSee('"type":"savings"');

    expect($child->fresh()->parent_id)->toBe($savings->id)
        ->and($child->fresh()->type)->toBe(CategoryType::Savings)
        ->and($child->fresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Outflow)
        // The whole subtree follows the new parent, not just the moved category.
        ->and($grandchild->fresh()->type)->toBe(CategoryType::Savings)
        ->and($grandchild->fresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Outflow);
});
