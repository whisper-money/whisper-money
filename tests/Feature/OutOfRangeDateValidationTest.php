<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * A six-digit year passes Laravel's `date` rule and used to reach MySQL, which
 * refused it with a 500 (PHP-LARAVEL-7D, PHP-LARAVEL-7E). Every endpoint that
 * writes a transaction or balance date now answers 422 instead.
 */

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->withBalance()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
});

it('rejects a six-digit year when creating a transaction', function () {
    actingAs($this->user)->postJson(route('transactions.store'), [
        'account_id' => $this->account->id,
        'description' => 'Imported row',
        'transaction_date' => '262004-08-10',
        'amount' => -1500,
        'currency_code' => 'EUR',
        'source' => 'imported',
    ])->assertUnprocessable()->assertJsonValidationErrors('transaction_date');

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects a six-digit year when editing a transaction date', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-15',
    ]);

    actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
        'transaction_date' => '262004-08-10',
    ])->assertUnprocessable()->assertJsonValidationErrors('transaction_date');

    expect($transaction->fresh()->transaction_date->toDateString())->toBe('2026-01-15');
});

it('rejects a six-digit year when recording a balance', function () {
    $balances = $this->account->balances()->count();

    actingAs($this->user)->postJson("/api/accounts/{$this->account->id}/balances", [
        'balance_date' => '261818-07-29',
        'balance' => 50000,
    ])->assertUnprocessable()->assertJsonValidationErrors('balance_date');

    expect($this->account->balances()->count())->toBe($balances);
});

it('rejects a six-digit year when checking import rows for duplicates', function () {
    actingAs($this->user)->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '262154-01-01', 'amount' => -1500, 'description' => 'Imported row'],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('transactions.0.transaction_date');
});

it('rejects a six-digit year from the MCP write tools', function (string $tool, Closure $arguments) {
    $this->user->withAccessToken($this->user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($this->user)
        ->tool($tool, $arguments($this->user, $this->account))
        ->assertHasErrors();
})->with([
    'create_transaction' => [CreateTransaction::class, fn (User $user, Account $account) => [
        'account_id' => $account->id,
        'description' => 'Coffee',
        'amount' => -450,
        'transaction_date' => '262213-03-04',
    ]],
    'update_transaction' => [UpdateTransaction::class, fn (User $user, Account $account) => [
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id])->id,
        'transaction_date' => '262213-03-04',
    ]],
    'create_balance' => [CreateBalance::class, fn (User $user, Account $account) => [
        'account_id' => $account->id,
        'balance' => 250000,
        'balance_date' => '262213-03-04',
    ]],
]);
