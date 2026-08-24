<?php

use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('splits a transaction into two parts and merges it back', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Split Account',
        'currency_code' => 'USD',
    ]);

    $original = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'SUPERMARKET RUN',
        'amount' => -5000,
        'transaction_date' => now()->toDateString(),
        'currency_code' => 'USD',
        'source' => TransactionSource::EnableBanking,
    ]);

    actingAs($user);

    $page = visit('/transactions');

    // The row opens the edit dialog, which carries the second way into a split.
    $page->assertSee('SUPERMARKET RUN')
        ->click('SUPERMARKET RUN')
        ->waitForText('Edit Transaction', 5)
        ->click('Split')
        ->waitForText('Split transaction', 5)
        ->fill('#split-amount-0', '30')
        ->fill('#split-amount-1', '20')
        ->waitForText('All shared out', 5)
        ->click('[data-testid="submit-split"]')
        ->wait(1.5)
        ->assertNoJavascriptErrors();

    expect(Transaction::query()->where('split_parent_id', $original->id)->pluck('amount')->sort()->values()->all())
        ->toBe([-3000, -2000])
        ->and(Transaction::query()->find($original->id))->toBeNull();
});
