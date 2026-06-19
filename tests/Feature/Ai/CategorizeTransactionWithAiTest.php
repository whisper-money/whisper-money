<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;

function eligible(): User
{
    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    return $user;
}

function leaf(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function fakeCategorizes(Transaction $marker, int $index): void
{
    TransactionCategorizationAgent::fake(function (string $prompt) use ($index) {
        // The ref is the transaction id, which the listener passes through.
        preg_match('/"ref":"([0-9a-f-]+)"/', $prompt, $m);

        return ['results' => [[
            'ref' => $m[1] ?? '',
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => false,
        ]]];
    });
}

it('categorizes an eligible uncategorized transaction on creation', function () {
    $user = eligible();
    $category = leaf($user);
    $index = (function () use ($user, $category): int {
        $catalog = CategoryCatalog::forUser($user);
        $i = 0;
        while ($catalog->categoryIdForIndex($i) !== null) {
            if ($catalog->categoryIdForIndex($i) === $category->id) {
                return $i;
            }
            $i++;
        }
        throw new RuntimeException('not a leaf');
    })();

    fakeCategorizes(new Transaction, $index);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]);

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category_source)->toBe(CategorySource::Ai);
});

it('does nothing when the user is not eligible', function () {
    $user = User::factory()->create();
    leaf($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('does not categorize transactions created while onboarding', function () {
    $user = eligible();
    $user->update(['onboarded_at' => null]);
    leaf($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('does not categorize a transaction that already has a category', function () {
    $user = eligible();
    $category = leaf($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
        'amount' => -4300,
    ]);

    expect($transaction->refresh()->category_source)->toBe(CategorySource::Manual);
});

it('never categorizes a client-side encrypted transaction', function () {
    $user = eligible();
    leaf($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'description_iv' => str_repeat('a', 16),
        'amount' => -4300,
    ]);

    expect($transaction->refresh()->category_id)->toBeNull();
});
