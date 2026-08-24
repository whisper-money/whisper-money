<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;

function eligibleOnboardedUser(): User
{
    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    return $user;
}

function leafIndexFor(CategoryCatalog $catalog, string $categoryId): int
{
    $index = 0;

    while (($id = $catalog->categoryIdForIndex($index)) !== null) {
        if ($id === $categoryId) {
            return $index;
        }
        $index++;
    }

    throw new RuntimeException("category {$categoryId} is not a leaf in the catalog");
}

function expenseLeaf(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function fakeCategorizesEachRef(int $index): void
{
    TransactionCategorizationAgent::fake(function (string $prompt) use ($index): array {
        preg_match_all('/"ref":"([0-9a-f-]+)"/', $prompt, $matches);

        return ['results' => array_map(fn (string $ref): array => [
            'ref' => $ref,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => false,
        ], $matches[1])];
    });
}

function runOnboardingCategorization(User $user): void
{
    app()->call([new CategorizeOnboardingTransactionsJob($user), 'handle']);
}

it('categorizes the remaining uncategorized transactions when onboarding completes', function () {
    $user = eligibleOnboardedUser();
    $category = expenseLeaf($user);
    $index = leafIndexFor(CategoryCatalog::forUser($user), $category->id);

    fakeCategorizesEachRef($index);

    $pending = Transaction::factory()->plaintext()->count(3)->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    runOnboardingCategorization($user);

    foreach ($pending as $transaction) {
        expect($transaction->refresh()->category_id)->toBe($category->id)
            ->and($transaction->category_source)->toBe(CategorySource::Ai);
    }
});

it('leaves transactions already categorized by rules untouched', function () {
    $user = eligibleOnboardedUser();
    $category = expenseLeaf($user);
    $index = leafIndexFor(CategoryCatalog::forUser($user), $category->id);

    fakeCategorizesEachRef($index);

    $alreadyCategorized = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'category_source' => CategorySource::Rule,
    ]);

    runOnboardingCategorization($user);

    expect($alreadyCategorized->refresh()->category_source)->toBe(CategorySource::Rule);
});

it('does nothing for a user who is not eligible', function () {
    $user = User::factory()->onboarded()->create();
    expenseLeaf($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    runOnboardingCategorization($user);

    expect($transaction->refresh()->category_id)->toBeNull();
});
