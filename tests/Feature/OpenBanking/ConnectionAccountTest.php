<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\AccountType;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

function onboardedUser(): User
{
    return User::factory()->onboarded()->create();
}

test('index renders the manage page with synced and available accounts', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-1',
        'currency_code' => 'EUR',
    ]);
    Account::factory()->for($user)->create([
        'banking_connection_id' => null,
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user)
        ->get(route('open-banking.connection-accounts.index', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('open-banking/manage-accounts')
            ->has('syncedAccounts', 1)
            ->has('availableAccounts', 1)
            ->where('discoveredAccounts', null)
        );
});

test('refresh discovers bank accounts that are not yet synced', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'synced-uid',
    ]);

    $this->mock(BankingProviderInterface::class, function ($mock) {
        $mock->shouldReceive('getSession')->andReturn([
            'status' => 'AUTHORIZED',
            'accounts' => [
                ['uid' => 'synced-uid'],
                ['uid' => 'new-uid'],
            ],
        ]);
        $mock->shouldReceive('getAccount')->with('new-uid')->andReturn([
            'uid' => 'new-uid',
            'account_id' => ['iban' => 'ES9999999999999999999999'],
            'currency' => 'EUR',
            'name' => 'Savings',
        ]);
    });

    $this->actingAs($user)
        ->get(route('open-banking.connection-accounts.index', [$connection, 'refresh' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('discoveredAccounts', 1)
            ->where('discoveredAccounts.0.uid', 'new-uid')
            ->where('discoveredAccounts.0.name', 'Savings')
            ->where('discoveredAccounts.0.iban', 'ES9999999999999999999999')
        );
});

test('map create adds a new synced account and dispatches a sync', function () {
    Queue::fake();

    $user = onboardedUser();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.connection-accounts.map', $connection), [
            'bank_account_uid' => 'new-uid',
            'action' => 'create',
            'name' => 'New Checking',
            'currency' => 'EUR',
            'iban' => 'ES1111111111111111111111',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'new-uid',
        'name' => 'New Checking',
        'currency_code' => 'EUR',
        'iban' => 'ES1111111111111111111111',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('map link moves syncing to another account and unlinks the previous one', function () {
    Queue::fake();

    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    $source = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-1',
        'iban' => 'ES2222222222222222222222',
        'currency_code' => 'EUR',
    ]);
    $target = Account::factory()->for($user)->create([
        'banking_connection_id' => null,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking->value,
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.connection-accounts.map', $connection), [
            'bank_account_uid' => 'uid-1',
            'action' => 'link',
            'existing_account_id' => $target->id,
        ])
        ->assertRedirect();

    expect($target->refresh())
        ->banking_connection_id->toBe($connection->id)
        ->external_account_id->toBe('uid-1')
        ->iban->toBe('ES2222222222222222222222')
        ->and($target->linked_at)->not->toBeNull();

    expect($source->refresh())
        ->banking_connection_id->toBeNull()
        ->external_account_id->toBeNull();
});

test('unlink stops syncing but keeps the account and its transactions', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-1',
    ]);
    $transaction = Transaction::factory()->for($account)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('open-banking.connection-accounts.unlink', [$connection, $account]))
        ->assertRedirect();

    expect($account->refresh())
        ->banking_connection_id->toBeNull()
        ->external_account_id->toBeNull();

    $this->assertDatabaseHas('accounts', ['id' => $account->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
});

test('index is forbidden for another user\'s connection', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create([
        'user_id' => User::factory()->onboarded()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('open-banking.connection-accounts.index', $connection))
        ->assertForbidden();
});

test('unlink rejects an account that does not belong to the connection', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => null,
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.connection-accounts.unlink', [$connection, $account]))
        ->assertNotFound();
});

test('map link rejects a non-transactional target account', function () {
    $user = onboardedUser();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $loan = Account::factory()->for($user)->create([
        'banking_connection_id' => null,
        'currency_code' => 'EUR',
        'type' => AccountType::Loan->value,
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.connection-accounts.map', $connection), [
            'bank_account_uid' => 'uid-x',
            'action' => 'link',
            'existing_account_id' => $loan->id,
        ])
        ->assertStatus(422);
});
