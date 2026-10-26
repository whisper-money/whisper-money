<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Jobs\RetryTransientAiCategorizationJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;

it('categorizes the still-pending transactions when run for a consenting user', function () {
    $user = User::factory()->create();
    $user->recordAiConsent();

    $category = aiExpenseCategory($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'category_source' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
        'description' => 'mercadona compra',
    ]);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    app()->call([new RetryTransientAiCategorizationJob($user), 'handle']);

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category_source)->toBe(CategorySource::Ai);
});

it('does nothing when the user has not consented to AI', function () {
    $user = User::factory()->create();

    aiExpenseCategory($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    TransactionCategorizationAgent::fake([])->preventStrayPrompts();

    app()->call([new RetryTransientAiCategorizationJob($user), 'handle']);

    $transaction->refresh();

    expect($transaction->category_id)->toBeNull();
    TransactionCategorizationAgent::assertNeverPrompted();
});
