<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\RuleSuggestionAggregator;

beforeEach(function () {
    config()->set('ai_suggestions.min_group_count', 3);
    config()->set('ai_suggestions.max_groups_sent', 15);
    $this->aggregator = new RuleSuggestionAggregator;
});

function makeTxn(User $user, Account $account, array $attributes): void
{
    Transaction::factory()->for($user)->create(array_merge([
        'account_id' => $account->id,
        'category_id' => null,
        'description_iv' => null,
    ], $attributes));
}

it('groups by counterparty and description, filtering rare and encrypted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    // Frequent counterparty group (4 txns).
    for ($i = 0; $i < 4; $i++) {
        makeTxn($user, $account, [
            'creditor_name' => 'MERCADONA',
            'description' => "COMPRA TARJ MERCADONA {$i}234",
            'amount' => -4210,
        ]);
    }

    // Frequent description-only group (3 txns, no counterparty).
    for ($i = 0; $i < 3; $i++) {
        makeTxn($user, $account, [
            'creditor_name' => null,
            'debtor_name' => null,
            'description' => "NETFLIX.COM AMSTERDAM {$i}99",
            'amount' => -1299,
        ]);
    }

    // Rare group (2 txns) — below the threshold.
    for ($i = 0; $i < 2; $i++) {
        makeTxn($user, $account, [
            'creditor_name' => 'RARE SHOP',
            'description' => 'RARE SHOP',
            'amount' => -500,
        ]);
    }

    // Encrypted transaction — must be ignored.
    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description_iv' => str_repeat('a', 16),
        'creditor_name' => 'MERCADONA',
        'amount' => -1000,
    ]);

    $groups = collect($this->aggregator->groupsFor($user));

    expect($groups)->toHaveCount(2);

    $mercadona = $groups->firstWhere('key', 'mercadona');
    expect($mercadona['field'])->toBe('creditor_name')
        ->and($mercadona['count'])->toBe(4)
        ->and($mercadona['direction'])->toBe('outflow')
        ->and($mercadona['avg_amount'])->toBe(-42.10);

    $netflix = $groups->firstWhere('field', 'description');
    expect($netflix['count'])->toBe(3)
        ->and($netflix['key'])->toContain('netflix');
});

it('builds a closed category list with paths, direction and leaf flags', function () {
    $user = User::factory()->create();
    $food = Category::factory()->for($user)->create(['name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['name' => 'Groceries']);

    $options = collect($this->aggregator->categoryOptions($user));

    $groceriesOption = $options->firstWhere('id', $groceries->id);
    $foodOption = $options->firstWhere('id', $food->id);

    expect($groceriesOption['path'])->toBe('Food > Groceries')
        ->and($groceriesOption['is_leaf'])->toBeTrue()
        ->and($foodOption['is_leaf'])->toBeFalse();
});
