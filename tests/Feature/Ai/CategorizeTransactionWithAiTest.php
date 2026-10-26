<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;

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
    $user = aiEligibleUser();
    $category = aiExpenseCategory($user);
    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

    fakeCategorizes(new Transaction, $index);

    $transaction = Transaction::factory()->create([
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
    aiExpenseCategory($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('does not categorize transactions created while onboarding', function () {
    $user = aiEligibleUser();
    $user->update(['onboarded_at' => null]);
    aiExpenseCategory($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('does not categorize a transaction that already has a category', function () {
    $user = aiEligibleUser();
    $category = aiExpenseCategory($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
        'amount' => -4300,
    ]);

    expect($transaction->refresh()->category_source)->toBe(CategorySource::Manual);
});
