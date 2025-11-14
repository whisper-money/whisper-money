<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns all accounts for authenticated user', function () {
    actingAs($this->user);

    $bank = Bank::factory()->create(['user_id' => $this->user->id]);

    $accounts = Account::factory()
        ->count(3)
        ->create([
            'user_id' => $this->user->id,
            'bank_id' => $bank->id,
        ]);

    Account::factory()
        ->count(2)
        ->create();

    $response = getJson('/api/sync/accounts');

    $response->assertSuccessful();
    $response->assertJsonCount(3, 'data');
});

it('returns only accounts updated after specified timestamp', function () {
    actingAs($this->user);

    $bank = Bank::factory()->create(['user_id' => $this->user->id]);

    $oldAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
        'updated_at' => now()->subDays(2),
    ]);

    $newAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
        'updated_at' => now(),
    ]);

    $response = getJson('/api/sync/accounts?since='.now()->subDay()->toIso8601String());

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $newAccount->id);
});

it('includes bank relationship', function () {
    actingAs($this->user);

    $bank = Bank::factory()->create(['user_id' => $this->user->id]);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
    ]);

    $response = getJson('/api/sync/accounts');

    $response->assertSuccessful();
    $response->assertJsonPath('data.0.bank.id', $bank->id);
});

it('requires authentication', function () {
    $response = getJson('/api/sync/accounts');

    $response->assertUnauthorized();
});

