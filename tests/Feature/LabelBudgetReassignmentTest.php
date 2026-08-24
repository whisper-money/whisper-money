<?php

use App\Enums\CategoryType;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Jobs\ReEvaluateTransactionRulesJob;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\LabelTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\Queue;

function periodCovering(Budget $budget): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);
}

function budgetsOf(Transaction $transaction): array
{
    return BudgetTransaction::query()
        ->where('transaction_id', $transaction->id)
        ->pluck('budget_period_id')
        ->all();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->label = Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Miami 26']);

    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $this->catchAllPeriod = periodCovering(Budget::factory()->catchAll()->create(['user_id' => $this->user->id]));
    $this->labelPeriod = periodCovering(Budget::factory()->forLabels($this->label)->create(['user_id' => $this->user->id]));

    // Created before it carries the label, so it lands in the catch-all — the
    // exact state each of the three label paths used to leave behind.
    $this->transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => Account::factory()->create(['user_id' => $this->user->id])->id,
        'category_id' => $category->id,
        'description' => 'Hotel Miami',
        'transaction_date' => now(),
        'amount' => -1000,
    ]);

    expect(budgetsOf($this->transaction))->toBe([$this->catchAllPeriod->id]);
});

function labelRule(User $user, Label $label): AutomationRule
{
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['hotel', ['var' => 'description']]],
        'action_category_id' => null,
    ]);

    $rule->labels()->attach($label->id);

    return $rule->load('labels');
}

test('a rule labelling a single transaction moves it into the label budget', function () {
    labelRule($this->user, $this->label);

    app(AutomationRuleService::class)->applyRules($this->transaction);

    expect(budgetsOf($this->transaction))->toBe([$this->labelPeriod->id]);
});

test('applying a rule in bulk moves every labelled transaction into the label budget', function () {
    $rule = labelRule($this->user, $this->label);

    $transactions = Transaction::query()->whereKey($this->transaction->id)->with('labels')->get();

    app(AutomationRuleService::class)->applyRuleActionsToTransactions($transactions, $rule);

    expect(budgetsOf($this->transaction))->toBe([$this->labelPeriod->id]);
});

test('the bulk apply reassigns transactions whose category it mass-updated', function () {
    $tracked = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $categoryPeriod = periodCovering(Budget::factory()->forCategories($tracked)->create(['user_id' => $this->user->id]));

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['hotel', ['var' => 'description']]],
        'action_category_id' => $tracked->id,
    ]);

    $transactions = Transaction::query()->whereKey($this->transaction->id)->with('labels')->get();

    app(AutomationRuleService::class)->applyRuleActionsToTransactions($transactions, $rule);

    expect(budgetsOf($this->transaction))->toBe([$categoryPeriod->id]);
});

test('the bulk actions bar moves the labelled transactions into the label budget', function () {
    $this->actingAs($this->user)
        ->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$this->transaction->id],
            'label_ids' => [$this->label->id],
        ])
        ->assertOk();

    expect(budgetsOf($this->transaction))->toBe([$this->labelPeriod->id]);
});

test('the bulk actions bar reassigns after a mass category update', function () {
    $tracked = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $categoryPeriod = periodCovering(Budget::factory()->forCategories($tracked)->create(['user_id' => $this->user->id]));

    $this->actingAs($this->user)
        ->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$this->transaction->id],
            'category_id' => $tracked->id,
        ])
        ->assertOk();

    expect(budgetsOf($this->transaction))->toBe([$categoryPeriod->id]);
});

test('re-evaluating the rules over the whole history queues one silent job per chunk', function () {
    Queue::fake();
    labelRule($this->user, $this->label);

    (new ReEvaluateTransactionRulesJob($this->user, 'job-1'))->handle(app(AutomationRuleService::class));

    Queue::assertPushed(
        ReassignTransactionsToBudgets::class,
        fn (ReassignTransactionsToBudgets $job): bool => $job->notify === false
            && $job->transactionIds === [$this->transaction->id],
    );
    Queue::assertPushed(ReassignTransactionsToBudgets::class, 1);
});

test('the MCP tool reassigns the transaction when it attaches and when it removes a label', function () {
    $this->user->withAccessToken($this->user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($this->user)->tool(LabelTransaction::class, [
        'transaction_id' => $this->transaction->id,
        'add_label_ids' => [$this->label->id],
    ])->assertOk();

    expect(budgetsOf($this->transaction))->toBe([$this->labelPeriod->id]);

    WhisperMoneyServer::actingAs($this->user)->tool(LabelTransaction::class, [
        'transaction_id' => $this->transaction->id,
        'remove_label_ids' => [$this->label->id],
    ])->assertOk();

    expect(budgetsOf($this->transaction))->toBe([$this->catchAllPeriod->id]);
});
