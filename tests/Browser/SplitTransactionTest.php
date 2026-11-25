<?php

use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;

use function Pest\Laravel\actingAs;

/**
 * A user with one bank transaction on a checking account (pinned: the factory
 * picks a random type).
 *
 * @return array{0: User, 1: Transaction}
 */
function userWithBankTransaction(string $description, int $amount): array
{
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Split Account',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => $description,
        'amount' => $amount,
        'transaction_date' => now()->toDateString(),
        'currency_code' => 'USD',
        'source' => TransactionSource::EnableBanking,
    ]);

    return [$user, $transaction];
}

it('splits a transaction into two parts from the edit dialog', function () {
    [$user, $original] = userWithBankTransaction('SUPERMARKET RUN', -5000);

    actingAs($user);

    $page = visit('/transactions');

    // The row opens the edit dialog, which carries the second way into a split.
    $page->assertSee('SUPERMARKET RUN')
        ->click('SUPERMARKET RUN')
        ->waitForText('Edit Transaction', 5)
        ->click('[data-testid="split-transaction"]')
        ->waitForText('Split transaction', 5)
        ->fill('#split-amount-0', '30')
        ->fill('#split-amount-1', '20')
        ->waitForText('All shared out', 5)
        ->click('[data-testid="submit-split"]')
        ->assertDontSee('All shared out')
        ->assertNoJavascriptErrors();

    expect(Transaction::query()->where('split_parent_id', $original->id)->pluck('amount')->sort()->values()->all())
        ->toBe([-3000, -2000])
        ->and(Transaction::query()->find($original->id))->toBeNull();
});

it('splits from the row menu, the entry point people actually use', function () {
    [$user, $original] = userWithBankTransaction('CORNER SHOP', -4000);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('CORNER SHOP')
        ->click('Open menu')
        ->click('[role="menuitem"]:has-text("Split")')
        ->waitForText('Split transaction', 5)
        ->fill('#split-amount-0', '25')
        ->fill('#split-amount-1', '15')
        ->waitForText('All shared out', 5)
        ->click('[data-testid="submit-split"]')
        ->assertDontSee('All shared out')
        ->assertNoJavascriptErrors();

    expect(Transaction::query()->where('split_parent_id', $original->id)->pluck('amount')->sort()->values()->all())
        ->toBe([-2500, -1500]);
});

it('marks the parts of a split and merges them back from the row menu', function () {
    [$user, $original] = userWithBankTransaction('HARDWARE STORE', -6000);
    app(TransactionSplitter::class)->split($original, [
        ['amount' => -4000, 'category_id' => null, 'label_ids' => []],
        ['amount' => -2000, 'category_id' => null, 'label_ids' => []],
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('HARDWARE STORE')
        ->assertCount('[data-testid="split-origin"]', 2)
        // Either part merges the whole split back.
        ->click('button:has-text("Open menu") >> nth=0')
        ->click('[role="menuitem"]:has-text("Merge the split back")')
        ->waitForText('Merge the 2 splits back?', 5)
        ->click('[data-testid="confirm-unsplit"]')
        ->assertDontSee('Merge the 2 splits back?')
        ->assertNoJavascriptErrors();

    expect(Transaction::query()->where('split_parent_id', $original->id)->count())->toBe(0)
        ->and(Transaction::query()->find($original->id))->not->toBeNull();
});
