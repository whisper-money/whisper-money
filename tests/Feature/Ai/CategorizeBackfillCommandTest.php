<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\artisan;

function bfCategory(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function bfFakeAllToIndex(int $index): void
{
    TransactionCategorizationAgent::fake(function (string $prompt) use ($index): array {
        preg_match_all('/"ref":"([^"]+)"/', $prompt, $matches);

        return ['results' => array_map(fn (string $ref): array => [
            'ref' => $ref,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ], $matches[1])];
    });
}

it('categorizes a user\'s uncategorized transactions and learns rules', function () {
    $user = User::factory()->create();
    $user->recordAiConsent();
    $category = bfCategory($user);

    $transactions = collect(range(1, 3))->map(fn (): Transaction => Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
    ]));

    bfFakeAllToIndex(0);

    artisan('ai:categorize-backfill', ['user' => $user->id])->assertSuccessful();

    $transactions->each(function (Transaction $transaction) use ($category): void {
        expect($transaction->refresh()->category_id)->toBe($category->id)
            ->and($transaction->category_source)->toBe(CategorySource::Ai);
    });

    expect(AutomationRule::query()->where('user_id', $user->id)->origin(RuleOrigin::Ai)->count())->toBe(1);
});

it('refuses to backfill an ineligible user', function () {
    $user = User::factory()->create();
    bfCategory($user);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    artisan('ai:categorize-backfill', ['user' => $user->id])->assertExitCode(1);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('reports when there is nothing to backfill', function () {
    $user = User::factory()->create();
    $user->recordAiConsent();
    bfCategory($user);

    artisan('ai:categorize-backfill', ['user' => $user->id])
        ->expectsOutputToContain('No uncategorized transactions to backfill.')
        ->assertSuccessful();
});
