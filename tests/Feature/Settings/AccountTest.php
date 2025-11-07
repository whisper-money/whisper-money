<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->bank = Bank::factory()->create();
});

it('displays user accounts on index page', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->get(route('accounts.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/accounts')
        ->has('accounts', 1)
        ->has('banks'));
});

it('can create a new account', function () {
    actingAs($this->user);

    $data = [
        'name' => 'encrypted_name_value',
        'name_iv' => 'abcd1234efgh5678',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect(route('accounts.index'));
    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'encrypted_name_value',
        'name_iv' => 'abcd1234efgh5678',
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ]);
});

it('validates required fields when creating account', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), []);

    $response->assertSessionHasErrors(['name', 'name_iv', 'bank_id', 'currency_code', 'type']);
});

it('validates name_iv must be exactly 16 characters', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'encrypted_name',
        'name_iv' => 'short',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ]);

    $response->assertSessionHasErrors(['name_iv']);
});

it('validates currency_code must be in allowed list', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'encrypted_name',
        'name_iv' => 'abcd1234efgh5678',
        'bank_id' => $this->bank->id,
        'currency_code' => 'INVALID',
        'type' => AccountType::Checking->value,
    ]);

    $response->assertSessionHasErrors(['currency_code']);
});

it('validates type must be valid AccountType', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'encrypted_name',
        'name_iv' => 'abcd1234efgh5678',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => 'invalid_type',
    ]);

    $response->assertSessionHasErrors(['type']);
});

it('can update an account', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $newBank = Bank::factory()->create();

    $data = [
        'name' => 'updated_encrypted_name',
        'name_iv' => 'newiv123456789ab',
        'bank_id' => $newBank->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Savings->value,
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));
    assertDatabaseHas('accounts', [
        'id' => $account->id,
        'name' => 'updated_encrypted_name',
        'name_iv' => 'newiv123456789ab',
        'bank_id' => $newBank->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Savings->value,
    ]);
});

it('prevents updating another users account', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $this->bank->id,
    ]);

    actingAs($this->user);

    $response = $this->patch(route('accounts.update', $account), [
        'name' => 'hacked_name',
        'name_iv' => 'abcd1234efgh5678',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ]);

    $response->assertForbidden();
});

it('can delete an account', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->delete(route('accounts.destroy', $account));

    $response->assertRedirect(route('accounts.index'));
    expect(Account::withTrashed()->find($account->id))->not->toBeNull();
    expect(Account::withTrashed()->find($account->id)->deleted_at)->not->toBeNull();
    expect(Account::find($account->id))->toBeNull();
});

it('deletes all transactions when deleting account', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $transaction1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
    ]);

    $transaction2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
    ]);

    $response = $this->delete(route('accounts.destroy', $account));

    $response->assertRedirect(route('accounts.index'));
    expect(Account::find($account->id))->toBeNull();
    expect(Account::withTrashed()->find($account->id))->not->toBeNull();
    expect(Transaction::find($transaction1->id))->toBeNull();
    expect(Transaction::find($transaction2->id))->toBeNull();
});

it('prevents deleting another users account', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $this->bank->id,
    ]);

    actingAs($this->user);

    $response = $this->delete(route('accounts.destroy', $account));

    $response->assertForbidden();
    assertDatabaseHas('accounts', ['id' => $account->id]);
});

it('only shows banks owned by user or global banks', function () {
    Bank::query()->delete();

    actingAs($this->user);

    $userBank = Bank::factory()->create(['user_id' => $this->user->id]);
    $globalBank = Bank::factory()->create(['user_id' => null]);
    $otherUserBank = Bank::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->get(route('accounts.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/accounts')
        ->has('banks', 2)
        ->where('banks.0.id', fn ($id) => in_array($id, [$userBank->id, $globalBank->id]))
        ->where('banks.1.id', fn ($id) => in_array($id, [$userBank->id, $globalBank->id])));
});
