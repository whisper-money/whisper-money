<?php

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
        'encrypted' => false,
    ]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

test('assigns category when "in" operator matches description', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store Purchase',
        'amount' => -5000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('assigns category when rule matches creditor name', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['amazon', ['var' => 'creditor_name']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Card payment',
        'creditor_name' => 'Amazon EU',
        'amount' => -5000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('assigns category when rule matches debtor name', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['payroll', ['var' => 'debtor_name']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Incoming transfer',
        'debtor_name' => 'Payroll GmbH',
        'amount' => 500000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
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

test('skips encrypted transactions', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'description_iv' => 'some-iv-value',
        'amount' => -5000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->not->toBe($this->category->id);
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

test('matches case-insensitively', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['GROCERY', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'grocery store',
        'amount' => -3000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
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

test('matches bank_name field', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['==' => [['var' => 'bank_name'], 'test bank']],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Some Payment',
        'amount' => -1000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('evaluates amount in dollars', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['>' => [['var' => 'amount'], 50]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Salary',
        'amount' => 10000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('does not match when amount condition fails', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['>' => [['var' => 'amount'], 200]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Small purchase',
        'amount' => 5000,
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

test('evaluates compound "and" rules', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => [
            'and' => [
                ['in' => ['grocery', ['var' => 'description']]],
                ['<' => [['var' => 'amount'], 0]],
            ],
        ],
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
});

test('compound "and" rule does not match when one condition fails', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => [
            'and' => [
                ['in' => ['grocery', ['var' => 'description']]],
                ['>' => [['var' => 'amount'], 0]],
            ],
        ],
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

test('evaluates "==" operator', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['==' => [['var' => 'description'], 'salary payment']],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Salary Payment',
        'amount' => 100000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('normalizes whitespace in description', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery store', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => '  Grocery   Store  Purchase  ',
        'amount' => -5000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('treats encrypted account names as empty string', function () {
    $encryptedAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'encrypted-data',
        'name_iv' => 'some-iv',
        'encrypted' => true,
    ]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['==' => [['var' => 'account_name'], '']],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $encryptedAccount->id,
        'description' => 'Some payment',
        'amount' => -1000,
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
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
