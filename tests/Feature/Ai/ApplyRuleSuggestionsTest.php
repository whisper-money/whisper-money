<?php

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\ApplyRuleSuggestions;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->action = app(ApplyRuleSuggestions::class);

    $this->makeTxn = function (array $attributes): Transaction {
        return Transaction::factory()->for($this->user)->create(array_merge([
            'account_id' => $this->account->id,
            'category_id' => null,
            'description_iv' => null,
        ], $attributes));
    };

    $this->group = fn (array $overrides = []): array => array_merge([
        'conditions' => [['field' => 'creditor_name', 'operator' => 'equals', 'token' => 'mercadona']],
        'proposed_category_id' => null,
        'new_category_name' => null,
        'new_category_direction' => null,
        'confidence' => 0.95,
    ], $overrides);
});

it('creates a rule and categorizes matching uncategorized transactions', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);

    $transactions = collect(range(1, 5))->map(fn (int $i) => ($this->makeTxn)([
        'creditor_name' => 'MERCADONA',
        'description' => "MERCADONA {$i}",
        'amount' => -4000,
    ]));

    $result = $this->action->apply($this->user, [
        ($this->group)(['proposed_category_id' => $groceries->id]),
    ], applyToExisting: true);

    expect($result)->toBe(['rules_created' => 1, 'transactions_categorized' => 5]);

    $rule = AutomationRule::query()->where('user_id', $this->user->id)->first();
    expect($rule->action_category_id)->toBe($groceries->id)
        ->and($rule->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']]);

    $transactions->each(fn (Transaction $t) => expect($t->fresh()->category_id)->toBe($groceries->id));
});

it('merges values heading to the same category into one OR rule', function () {
    $online = Category::factory()->for($this->user)->create(['name' => 'Online services', 'type' => 'expense']);

    collect(range(1, 2))->each(fn (int $i) => ($this->makeTxn)(['creditor_name' => null, 'description' => "LARAVEL FORGE {$i}", 'amount' => -1200]));
    collect(range(1, 3))->each(fn (int $i) => ($this->makeTxn)(['creditor_name' => null, 'description' => "DIGITALOCEAN {$i}", 'amount' => -500]));

    $result = $this->action->apply($this->user, [
        ($this->group)([
            'conditions' => [
                ['field' => 'description', 'operator' => 'contains', 'token' => 'laravel forge'],
                ['field' => 'description', 'operator' => 'contains', 'token' => 'digitalocean'],
            ],
            'proposed_category_id' => $online->id,
        ]),
    ], applyToExisting: true);

    expect($result)->toBe(['rules_created' => 1, 'transactions_categorized' => 5]);

    $rule = AutomationRule::query()->where('user_id', $this->user->id)->first();
    expect($rule->rules_json)->toBe(['or' => [
        ['in' => ['laravel forge', ['var' => 'description']]],
        ['in' => ['digitalocean', ['var' => 'description']]],
    ]]);
});

it('creates a proposed new category before applying the rule', function () {
    collect(range(1, 4))->each(fn (int $i) => ($this->makeTxn)([
        'creditor_name' => null,
        'description' => "NETFLIX {$i}",
        'amount' => -1300,
    ]));

    $result = $this->action->apply($this->user, [
        ($this->group)([
            'conditions' => [['field' => 'description', 'operator' => 'contains', 'token' => 'netflix']],
            'new_category_name' => 'Streaming',
            'new_category_direction' => 'outflow',
            'confidence' => 0.9,
        ]),
    ], applyToExisting: true);

    $category = Category::query()->where('user_id', $this->user->id)->where('name', 'Streaming')->first();

    expect($category)->not->toBeNull()
        ->and($category->type->value)->toBe('expense')
        ->and($category->cashflow_direction->value)->toBe('outflow')
        ->and($result['transactions_categorized'])->toBe(4);
});

it('creates the rule but does not categorize when applyToExisting is false', function () {
    $groceries = Category::factory()->for($this->user)->create(['type' => 'expense']);
    $txn = ($this->makeTxn)(['creditor_name' => 'MERCADONA', 'description' => 'MERCADONA', 'amount' => -4000]);

    $result = $this->action->apply($this->user, [
        ($this->group)(['proposed_category_id' => $groceries->id]),
    ], applyToExisting: false);

    expect($result['rules_created'])->toBe(1)
        ->and($result['transactions_categorized'])->toBe(0)
        ->and($txn->fresh()->category_id)->toBeNull();
});

it('lets the more specific rule win an overlapping transaction', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $streaming = Category::factory()->for($this->user)->create(['name' => 'Streaming', 'type' => 'expense']);

    // Broad "mercadona" matches 3 transactions; narrow "netflix" matches only the bundle.
    $bundle = ($this->makeTxn)(['creditor_name' => null, 'description' => 'MERCADONA NETFLIX BUNDLE', 'amount' => -4000]);
    collect(range(1, 2))->each(fn (int $i) => ($this->makeTxn)(['creditor_name' => null, 'description' => "MERCADONA {$i}", 'amount' => -4000]));

    $broad = ($this->group)([
        'conditions' => [['field' => 'description', 'operator' => 'contains', 'token' => 'mercadona']],
        'proposed_category_id' => $groceries->id,
        'confidence' => 0.95,
    ]);
    $narrow = ($this->group)([
        'conditions' => [['field' => 'description', 'operator' => 'contains', 'token' => 'netflix']],
        'proposed_category_id' => $streaming->id,
        'confidence' => 0.80,
    ]);

    $this->action->apply($this->user, [$broad, $narrow], applyToExisting: true);

    expect($bundle->fresh()->category_id)->toBe($streaming->id);
});
