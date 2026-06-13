<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\RuleSuggestionGuard;

beforeEach(function () {
    config()->set('ai_suggestions.confidence_floor', 0.7);
    config()->set('ai_suggestions.overbroad_fraction', 0.4);

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->guard = app(RuleSuggestionGuard::class);

    $make = function (string $description, ?string $creditor, int $amount): void {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->account->id,
            'category_id' => null,
            'description_iv' => null,
            'creditor_name' => $creditor,
            'debtor_name' => null,
            'description' => $description,
            'amount' => $amount,
        ]);
    };

    // 6 Mercadona (outflow), 4 Netflix (outflow), 10 unique "various shop" (outflow) = 20 total.
    for ($i = 0; $i < 6; $i++) {
        $make("MERCADONA COMPRA {$i}", 'MERCADONA', -4000);
    }
    for ($i = 0; $i < 4; $i++) {
        $make("NETFLIX {$i}", null, -1300);
    }
    for ($i = 0; $i < 10; $i++) {
        $make("VARIOUS SHOP {$i}", null, -1000);
    }

    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $this->salary = Category::factory()->for($this->user)->create(['name' => 'Salary', 'type' => 'income']);

    $this->categoryOptions = [
        ['id' => $this->groceries->id, 'name' => 'Groceries', 'path' => 'Groceries', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true],
        ['id' => $this->salary->id, 'name' => 'Salary', 'path' => 'Salary', 'type' => 'income', 'direction' => 'inflow', 'is_leaf' => true],
    ];
});

it('keeps a valid suggestion and computes group size + samples', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'mercadona', 'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'MERCADONA', 'category_id' => $this->groceries->id, 'confidence' => 0.95],
    ], $this->categoryOptions);

    expect($result)->toHaveCount(1);
    expect($result[0]['match_token'])->toBe('mercadona')
        ->and($result[0]['proposed_category_id'])->toBe($this->groceries->id)
        ->and($result[0]['group_size'])->toBe(6)
        ->and($result[0]['sample_descriptions'])->not->toBeEmpty();
});

it('rejects low-confidence, over-broad, absent-token and disallowed-field suggestions', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'netflix', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'netflix', 'category_id' => $this->groceries->id, 'confidence' => 0.5],
        ['group_key' => 'shop', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'shop', 'category_id' => $this->groceries->id, 'confidence' => 0.95],
        ['group_key' => 'ghost', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'zzzzz', 'category_id' => $this->groceries->id, 'confidence' => 0.95],
        ['group_key' => 'notes', 'match_field' => 'notes', 'match_operator' => 'contains', 'match_token' => 'whatever', 'category_id' => $this->groceries->id, 'confidence' => 0.95],
    ], $this->categoryOptions);

    expect($result)->toBeEmpty();
});

it('rejects a token that only matches a single transaction', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'one-off', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'mercadona compra 0', 'category_id' => $this->groceries->id, 'confidence' => 0.95],
    ], $this->categoryOptions);

    expect($result)->toBeEmpty();
});

it('rejects a suggestion whose category direction conflicts with the group', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'netflix', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'netflix', 'category_id' => $this->salary->id, 'confidence' => 0.95],
    ], $this->categoryOptions);

    expect($result)->toBeEmpty();
});

it('accepts a new-category proposal when no existing category is given', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'netflix', 'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'netflix', 'category_id' => '', 'new_category_name' => 'Streaming', 'confidence' => 0.9],
    ], $this->categoryOptions);

    expect($result)->toHaveCount(1);
    expect($result[0]['proposed_category_id'])->toBeNull()
        ->and($result[0]['new_category_name'])->toBe('Streaming')
        ->and($result[0]['new_category_direction'])->toBe('outflow')
        ->and($result[0]['group_size'])->toBe(4);
});

it('keeps only the highest-confidence suggestion per identical matcher', function () {
    $result = $this->guard->validate($this->user, [
        ['group_key' => 'mercadona', 'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona', 'category_id' => $this->groceries->id, 'confidence' => 0.80],
        ['group_key' => 'mercadona', 'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona', 'category_id' => $this->groceries->id, 'confidence' => 0.97],
    ], $this->categoryOptions);

    expect($result)->toHaveCount(1)
        ->and($result[0]['confidence'])->toBe(0.97);
});
