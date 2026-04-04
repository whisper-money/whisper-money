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
        ->has('accounts', 1));
});

it('can create a new account with plaintext name', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My Checking Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();
    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'My Checking Account',
        'name_iv' => null,
        'encrypted' => false,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ]);
});

it('can create a new account without a bank', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My Savings Account',
        'currency_code' => 'USD',
        'type' => AccountType::Savings->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();
    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'bank_id' => null,
        'name' => 'My Savings Account',
        'currency_code' => 'USD',
        'type' => AccountType::Savings->value,
    ]);
});

it('validates required fields when creating account', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), []);

    $response->assertSessionHasErrors(['name', 'currency_code', 'type']);
});

it('validates currency_code must be in allowed list', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'My Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'INVALID',
        'type' => AccountType::Checking->value,
    ]);

    $response->assertSessionHasErrors(['currency_code']);
});

it('accepts new latam currency when creating account', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Argentina Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'ARS',
        'type' => AccountType::Checking->value,
    ]);

    $response->assertRedirect();

    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'currency_code' => 'ARS',
    ]);
});

it('accepts bitcoin when creating account', function () {
    actingAs($this->user);

    Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
    ]);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Bitcoin Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'BTC',
        'type' => AccountType::Investment->value,
    ]);

    $response->assertRedirect();

    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'currency_code' => 'BTC',
    ]);
});

it('rejects bitcoin when creating a first account', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Bitcoin Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'BTC',
        'type' => AccountType::Investment->value,
    ]);

    $response->assertSessionHasErrors(['currency_code']);
});

it('validates type must be valid AccountType', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'My Account',
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
        'name' => 'Updated Account Name',
        'bank_id' => $newBank->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Savings->value,
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));
    assertDatabaseHas('accounts', [
        'id' => $account->id,
        'name' => 'Updated Account Name',
        'encrypted' => false,
        'name_iv' => null,
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

it('can create an account with an initial balance', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My Savings Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Savings->value,
        'balance' => 150000,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::where('user_id', $this->user->id)
        ->where('name', 'My Savings Account')
        ->first();

    expect($account)->not->toBeNull();

    assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 150000,
    ]);
});

it('creates account without balance record when balance is not provided', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My Investment Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Investment->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::where('user_id', $this->user->id)
        ->where('name', 'My Investment Account')
        ->first();

    expect($account)->not->toBeNull();

    assertDatabaseMissing('account_balances', [
        'account_id' => $account->id,
    ]);
});

it('validates balance must be an integer when provided', function () {
    actingAs($this->user);

    $response = $this->post(route('accounts.store'), [
        'name' => 'My Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Savings->value,
        'balance' => 'not-a-number',
    ]);

    $response->assertSessionHasErrors(['balance']);
});
