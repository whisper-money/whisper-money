<?php

use App\Enums\RuleSuggestionStatus;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\ApplyRuleSuggestions;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->run = SuggestionRun::factory()->for($this->user)->create();
    $this->action = app(ApplyRuleSuggestions::class);

    $this->makeTxn = function (array $attributes): Transaction {
        return Transaction::factory()->for($this->user)->create(array_merge([
            'account_id' => $this->account->id,
            'category_id' => null,
            'description_iv' => null,
        ], $attributes));
    };
});

it('creates a rule and categorizes matching uncategorized transactions', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);

    $transactions = collect(range(1, 5))->map(fn (int $i) => ($this->makeTxn)([
        'creditor_name' => 'MERCADONA',
        'description' => "MERCADONA {$i}",
        'amount' => -4000,
    ]));

    $suggestion = RuleSuggestion::factory()->for($this->run, 'run')->create([
        'match_field' => 'creditor_name',
        'match_operator' => 'equals',
        'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id,
        'confidence' => 0.95,
        'group_size' => 5,
    ]);

    $result = $this->action->apply($this->user, new Collection([$suggestion]), applyToExisting: true);

    expect($result)->toBe(['rules_created' => 1, 'transactions_categorized' => 5]);

    $rule = AutomationRule::query()->where('user_id', $this->user->id)->first();
    expect($rule->action_category_id)->toBe($groceries->id)
        ->and($rule->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']])
        ->and($suggestion->fresh()->status)->toBe(RuleSuggestionStatus::Accepted);

    $transactions->each(fn (Transaction $t) => expect($t->fresh()->category_id)->toBe($groceries->id));
});

it('creates a proposed new category before applying the rule', function () {
    collect(range(1, 4))->each(fn (int $i) => ($this->makeTxn)([
        'creditor_name' => null,
        'description' => "NETFLIX {$i}",
        'amount' => -1300,
    ]));

    $suggestion = RuleSuggestion::factory()->for($this->run, 'run')->proposesNewCategory('Streaming', 'outflow')->create([
        'match_field' => 'description',
        'match_operator' => 'contains',
        'match_token' => 'netflix',
        'confidence' => 0.9,
        'group_size' => 4,
    ]);

    $result = $this->action->apply($this->user, new Collection([$suggestion]), applyToExisting: true);

    $category = Category::query()->where('user_id', $this->user->id)->where('name', 'Streaming')->first();

    expect($category)->not->toBeNull()
        ->and($category->type->value)->toBe('expense')
        ->and($category->cashflow_direction->value)->toBe('outflow')
        ->and($result['transactions_categorized'])->toBe(4)
        ->and($suggestion->fresh()->proposed_category_id)->toBe($category->id);
});

it('creates the rule but does not categorize when applyToExisting is false', function () {
    $groceries = Category::factory()->for($this->user)->create(['type' => 'expense']);
    $txn = ($this->makeTxn)(['creditor_name' => 'MERCADONA', 'description' => 'MERCADONA', 'amount' => -4000]);

    $suggestion = RuleSuggestion::factory()->for($this->run, 'run')->create([
        'match_field' => 'creditor_name',
        'match_operator' => 'equals',
        'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id,
    ]);

    $result = $this->action->apply($this->user, new Collection([$suggestion]), applyToExisting: false);

    expect($result['rules_created'])->toBe(1)
        ->and($result['transactions_categorized'])->toBe(0)
        ->and($txn->fresh()->category_id)->toBeNull();
});

it('lets the higher-confidence rule win an overlapping transaction', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $streaming = Category::factory()->for($this->user)->create(['name' => 'Streaming', 'type' => 'expense']);

    $txn = ($this->makeTxn)([
        'creditor_name' => 'MERCADONA',
        'description' => 'MERCADONA NETFLIX BUNDLE',
        'amount' => -4000,
    ]);

    $high = RuleSuggestion::factory()->for($this->run, 'run')->create([
        'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id, 'confidence' => 0.95, 'group_size' => 1,
    ]);
    $low = RuleSuggestion::factory()->for($this->run, 'run')->create([
        'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'netflix',
        'proposed_category_id' => $streaming->id, 'confidence' => 0.80, 'group_size' => 1,
    ]);

    $this->action->apply($this->user, new Collection([$low, $high]), applyToExisting: true);

    expect($txn->fresh()->category_id)->toBe($groceries->id);
});
