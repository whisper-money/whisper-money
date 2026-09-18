<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bank = Bank::factory()->create();
});

it('returns all accounts for the authenticated user', function () {
    actingAs($this->user);

    Account::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    // Create account for another user (should not appear)
    Account::factory()->create([
        'user_id' => User::factory()->create()->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->getJson('/api/accounts');

    $response->assertOk();
    $response->assertJsonCount(3);
    $response->assertJsonStructure([
        '*' => ['id', 'name', 'bank_id', 'type', 'currency_code'],
    ]);
});

it('can update an account name', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'Old Name',
    ]);

    $response = $this->putJson("/api/accounts/{$account->id}", [
        'name' => 'My Checking Account',
    ]);

    $response->assertOk();
    assertDatabaseHas('accounts', [
        'id' => $account->id,
        'name' => 'My Checking Account',
    ]);
});

it('prevents updating another users account via api', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->putJson("/api/accounts/{$account->id}", [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
});

it('validates required fields when updating via api', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->putJson("/api/accounts/{$account->id}", []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
});

it('requires authentication for api endpoints', function () {
    $response = $this->getJson('/api/accounts');
    $response->assertUnauthorized();
});
