<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    actingAs($this->user);
});

it('flags transactions that already exist on the account', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-15',
        'amount' => 1234,
        'description' => 'Coffee Shop',
    ]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => 'Coffee Shop'],
            ['transaction_date' => '2026-01-15', 'amount' => 1235, 'description' => 'Coffee Shop'],
        ],
    ]);

    $response->assertOk()->assertJson(['duplicates' => [true, false]]);
});

it('normalizes case and whitespace when matching descriptions', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-15',
        'amount' => 1234,
        'description' => 'Coffee Shop',
    ]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => "  cOFFee   shOP  \n"],
        ],
    ]);

    $response->assertOk()->assertJson(['duplicates' => [true]]);
});

it('scopes duplicate detection to the given account', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $otherAccount->id,
        'transaction_date' => '2026-01-15',
        'amount' => 1234,
        'description' => 'Coffee Shop',
    ]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => 'Coffee Shop'],
        ],
    ]);

    $response->assertOk()->assertJson(['duplicates' => [false]]);
});

it('does not leak another users account', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->create(['user_id' => $stranger->id]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $strangerAccount->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => 'Coffee Shop'],
        ],
    ]);

    $response->assertNotFound();
});

it('treats non-breaking spaces as regular whitespace when matching', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-15',
        'amount' => 1234,
        'description' => 'Coffee Shop',
    ]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => "Coffee\u{00A0}Shop"],
        ],
    ]);

    $response->assertOk()->assertJson(['duplicates' => [true]]);
});

it('only matches existing transactions within the incoming date range', function () {
    // Same amount + description as the incoming row, but outside its date range.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-12-01',
        'amount' => 1234,
        'description' => 'Coffee Shop',
    ]);

    $response = $this->postJson('/api/transactions/check-duplicates', [
        'account_id' => $this->account->id,
        'transactions' => [
            ['transaction_date' => '2026-01-15', 'amount' => 1234, 'description' => 'Coffee Shop'],
        ],
    ]);

    $response->assertOk()->assertJson(['duplicates' => [false]]);
});

it('validates the request payload', function () {
    $response = $this->postJson('/api/transactions/check-duplicates', [
        'transactions' => [
            ['transaction_date' => 'not-a-date', 'amount' => 'abc', 'description' => ''],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'account_id',
        'transactions.0.transaction_date',
        'transactions.0.amount',
        'transactions.0.description',
    ]);
});
