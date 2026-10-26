<?php

use App\Enums\CategorySource;
use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create(['name' => 'Test Bank', 'user_id' => $this->user->id]);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'Checking Account',
    ]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

test('assigns the rule category when the rule matches the transaction', function (array $rulesJson, array $attributes) {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => $rulesJson,
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$attributes,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
})->with([
    '"in" on the description' => [
        ['in' => ['grocery', ['var' => 'description']]],
        ['description' => 'Grocery Store Purchase', 'amount' => -5000],
    ],
    '"in" on the creditor name' => [
        ['in' => ['amazon', ['var' => 'creditor_name']]],
        ['description' => 'Card payment', 'creditor_name' => 'Amazon EU', 'amount' => -5000],
    ],
    '"in" on the debtor name' => [
        ['in' => ['payroll', ['var' => 'debtor_name']]],
        ['description' => 'Incoming transfer', 'debtor_name' => 'Payroll GmbH', 'amount' => 500000],
    ],
    // "Does not contain" is how a user writes an exception, and a transaction whose
    // creditor the bank never sent is one the exception should cover.
    '"does not contain" with no creditor name' => [
        ['!' => ['in' => ['amazon', ['var' => 'creditor_name']]]],
        ['description' => 'Card payment', 'creditor_name' => null, 'amount' => -2000],
    ],
    'case-insensitive match' => [
        ['in' => ['GROCERY', ['var' => 'description']]],
        ['description' => 'grocery store', 'amount' => -3000],
    ],
    'bank_name field' => [
        ['==' => [['var' => 'bank_name'], 'test bank']],
        ['description' => 'Some Payment', 'amount' => -1000],
    ],
    'amount evaluated in dollars' => [
        ['>' => [['var' => 'amount'], 50]],
        ['description' => 'Salary', 'amount' => 10000],
    ],
    'compound "and"' => [
        ['and' => [['in' => ['grocery', ['var' => 'description']]], ['<' => [['var' => 'amount'], 0]]]],
        ['description' => 'Grocery Store', 'amount' => -5000],
    ],
    '"==" operator' => [
        ['==' => [['var' => 'description'], 'salary payment']],
        ['description' => 'Salary Payment', 'amount' => 100000],
    ],
    'whitespace normalized in the description' => [
        ['in' => ['grocery store', ['var' => 'description']]],
        ['description' => '  Grocery   Store  Purchase  ', 'amount' => -5000],
    ],
]);

test('leaves the transaction uncategorized when the rule does not match it', function (array $rulesJson, array $attributes) {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => $rulesJson,
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$attributes,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBeNull();
})->with([
    '"does not equal" skips the exact description it excludes' => [
        ['!=' => [['var' => 'description'], 'salary payment']],
        ['description' => 'Salary Payment', 'amount' => 100000, 'category_id' => null],
    ],
    'amount condition fails' => [
        ['>' => [['var' => 'amount'], 200]],
        ['description' => 'Small purchase', 'amount' => 5000, 'category_id' => null],
    ],
    'compound "and" with one failing condition' => [
        ['and' => [['in' => ['grocery', ['var' => 'description']]], ['>' => [['var' => 'amount'], 0]]]],
        ['description' => 'Grocery Store', 'amount' => -5000, 'category_id' => null],
    ],
]);

test('matches an exception rule that excludes a second token', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['and' => [
            ['in' => ['keyword', ['var' => 'description']]],
            ['!' => ['in' => ['visa card', ['var' => 'description']]]],
        ]],
        'action_category_id' => $this->category->id,
    ]);

    $matching = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Keyword Purchase',
        'amount' => -4000,
    ]);

    $excluded = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Keyword Purchase Visa Card',
        'amount' => -4000,
        'category_id' => null,
    ]);

    app(AutomationRuleService::class)->applyRules($matching);
    app(AutomationRuleService::class)->applyRules($excluded);

    expect($matching->fresh()->category_id)->toBe($this->category->id)
        ->and($excluded->fresh()->category_id)->toBeNull();
});

test('assigns labels when rule matches', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => null,
    ]);
    $rule->labels()->attach($label);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -2000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->labels)->toHaveCount(1)
        ->and($transaction->fresh()->labels->first()->id)->toBe($label->id);
});

test('assigns both category and labels', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['coffee', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);
    $rule->labels()->attach($label);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Coffee Shop Downtown',
        'amount' => -450,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    $fresh = $transaction->fresh();
    expect($fresh->category_id)->toBe($this->category->id)
        ->and($fresh->labels)->toHaveCount(1);
});

test('uses first-match-wins with priority ordering', function () {
    $categoryLow = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryHigh = Category::factory()->create(['user_id' => $this->user->id]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['store', ['var' => 'description']]],
        'action_category_id' => $categoryLow->id,
    ]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 10,
        'rules_json' => ['in' => ['store', ['var' => 'description']]],
        'action_category_id' => $categoryHigh->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Electronics Store',
        'amount' => -10000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($categoryLow->id);
});

test('does not apply rules from other users', function () {
    $otherUser = User::factory()->onboarded()->create();

    AutomationRule::factory()->create([
        'user_id' => $otherUser->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => null,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('does not fire TransactionUpdated when applying category', function () {
    Event::fake([TransactionUpdated::class]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
    Event::assertNotDispatched(TransactionUpdated::class);
});

test('applies automation rules via listener on TransactionCreated event', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
    ]);

    // The TransactionCreated event is dispatched automatically via $dispatchesEvents.
    // The listener should have already run. Verify the result.
    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('leaves a category the user set at creation time alone', function () {
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $ruleLabel = Label::factory()->create(['user_id' => $this->user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $ruleCategory->id,
    ]);
    $rule->labels()->attach($ruleLabel->id);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->category_id)->toBe($this->category->id)
        ->and($fresh->category_source)->toBe(CategorySource::Manual)
        ->and($fresh->categorized_by_rule_id)->toBeNull()
        ->and($fresh->labels)->toBeEmpty();
});
