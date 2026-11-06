<?php

use App\Enums\LabelSource;
use App\Models\Account;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

/** A transaction on the account, carrying the estimate label or one of the user's own. */
function estimateRow(Account $account, string $date, LabelSource $source = LabelSource::Estimate): Transaction
{
    $transaction = Transaction::factory()->for($account->user)->for($account)->create([
        'transaction_date' => $date,
        'amount' => -5000,
        'currency_code' => 'EUR',
        'description' => 'Groceries',
    ]);

    $label = Label::query()->firstOrCreate(
        ['user_id' => $account->user_id, 'source' => $source],
        ['name' => $source === LabelSource::Estimate ? 'Estimate' : 'Estimate (mine)', 'color' => 'amber'],
    );
    $transaction->labels()->attach($label);

    return $transaction;
}

it('lists the estimates in the months a file covers', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency_code' => 'EUR']);

    $october = estimateRow($account, '2026-10-01');
    $september = estimateRow($account, '2026-09-30');
    estimateRow($account, '2026-08-01');
    // A label of the user's own is not an estimate, whatever it is called.
    estimateRow($account, '2026-10-02', LabelSource::User);

    $this->actingAs($user)
        ->getJson('/api/transactions/estimates?'.http_build_query([
            'account_id' => $account->id,
            'months' => ['2026-09', '2026-10'],
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $september->id)
        ->assertJsonPath('data.1.id', $october->id)
        ->assertJsonPath('data.1.transaction_date', '2026-10-01')
        ->assertJsonPath('data.1.amount', -5000);
});

it('ignores estimates on another account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = Account::factory()->for($user)->create();
    estimateRow($other, '2026-10-01');

    $this->actingAs($user)
        ->getJson('/api/transactions/estimates?'.http_build_query([
            'account_id' => $account->id,
            'months' => ['2026-10'],
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses another user’s account and malformed months', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->for(User::factory())->create();
    $own = Account::factory()->for($user)->create();

    $this->actingAs($user)
        ->getJson('/api/transactions/estimates?'.http_build_query([
            'account_id' => $foreign->id,
            'months' => ['2026-10'],
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/transactions/estimates?'.http_build_query([
            'account_id' => $own->id,
            'months' => ['October'],
        ]))
        ->assertUnprocessable();
});
