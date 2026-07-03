<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

it('filters transactions by account, newest first', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);

    $older = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-01-01',
    ]);
    $newer = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-01',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $otherAccount->id,
    ]);

    $response = $this->getJson('/api/transactions?account_id='.$account->id);

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$newer->id, $older->id]);
});

it('caps the page size at 100', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
    ]);

    $response = $this->getJson('/api/transactions?account_id='.$account->id.'&per_page=999999');

    $response->assertOk()->assertJsonPath('per_page', 100);
});

it('clamps a non-positive page size up to 1', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson('/api/transactions?account_id='.$account->id.'&per_page=0');

    $response->assertOk()->assertJsonPath('per_page', 1);
});

it('does not return transactions from another users account', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->create(['user_id' => $stranger->id]);

    Transaction::factory()->count(2)->create([
        'user_id' => $stranger->id,
        'account_id' => $strangerAccount->id,
    ]);

    $response = $this->getJson('/api/transactions?account_id='.$strangerAccount->id);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});
