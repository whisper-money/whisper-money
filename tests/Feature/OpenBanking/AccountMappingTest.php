<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('show returns mapping page with correct props', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('open-banking.map-accounts', $connection));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('open-banking/map-accounts')
        ->has('connection')
        ->has('bankAccounts')
        ->has('existingAccounts')
    );
});

test('show redirects if no pending accounts', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'pending_accounts_data' => null,
    ]);

    $response = $this->actingAs($user)
        ->get(route('open-banking.map-accounts', $connection));

    $response->assertRedirect(route('settings.connections.index'));
});

test('show auto-creates pending accounts and updates currency during onboarding', function () {
    Queue::fake();

    $user = User::factory()->notOnboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Euro Checking',
                'account_id' => ['iban' => 'ES1234567890'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('open-banking.map-accounts', $connection))
        ->assertRedirect(route('onboarding', ['step' => 'create-account']));

    expect($user->refresh()->currency_code)->toBe('EUR');

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-1',
        'currency_code' => 'EUR',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('show returns 403 for other user\'s connection', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('open-banking.map-accounts', $connection));

    $response->assertForbidden();
});

test('store with action create creates new accounts', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Test Checking',
                'account_id' => ['iban' => 'ES1234567890'],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'create',
                    'existing_account_id' => null,
                ],
            ],
        ]);

    $response->assertRedirect(route('settings.connections.index'));

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-1',
        'name' => 'Test Checking',
        'currency_code' => 'EUR',
        'iban' => 'ES1234567890',
    ]);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->pending_accounts_data)->toBeNull();

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('store creates investment accounts for crypto provider connections', function (string $provider, string $providerName, string $uid) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'provider' => $provider,
        'aspsp_name' => $providerName,
        'pending_accounts_data' => [
            [
                'uid' => $uid,
                'currency' => 'EUR',
                'name' => 'Crypto Portfolio',
                'account_id' => [],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => $uid,
                    'action' => 'create',
                    'existing_account_id' => null,
                ],
            ],
        ]);

    $response->assertRedirect(route('settings.connections.index'));

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => $uid,
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
})->with([
    'bitpanda' => ['bitpanda', 'Bitpanda', 'bitpanda-portfolio'],
    'coinbase' => ['coinbase', 'Coinbase', 'coinbase-portfolio'],
]);

test('store updates user currency from first account created from mapping', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Euro Checking',
                'account_id' => ['iban' => 'ES1234567890'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'create',
                    'existing_account_id' => null,
                ],
            ],
        ])
        ->assertRedirect(route('settings.connections.index'));

    expect($user->refresh()->currency_code)->toBe('EUR');
});

test('store does not update user currency from later mapped accounts', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $existingConnection = BankingConnection::factory()->create(['user_id' => $user->id]);

    Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'banking_connection_id' => $existingConnection->id,
        'external_account_id' => 'existing-ext',
    ]);

    $user->update(['currency_code' => 'USD']);

    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Second Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-2',
                'currency' => 'GBP',
                'name' => 'Pound Checking',
                'account_id' => ['iban' => 'GB1234567890'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-2',
                    'action' => 'create',
                    'existing_account_id' => null,
                ],
            ],
        ])
        ->assertRedirect(route('settings.connections.index'));

    expect($user->refresh()->currency_code)->toBe('USD');
});

test('store updates user currency when linking first account', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $bank = Bank::factory()->create();
    $existingAccount = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'currency_code' => 'EUR',
        'banking_connection_id' => null,
    ]);

    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Bank Account',
                'account_id' => ['iban' => 'ES1234567890'],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'link',
                    'existing_account_id' => $existingAccount->id,
                ],
            ],
        ])
        ->assertRedirect(route('settings.connections.index'));

    expect($user->refresh()->currency_code)->toBe('EUR');
});

test('store with action link links existing account', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create();
    $existingAccount = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'currency_code' => 'EUR',
        'banking_connection_id' => null,
    ]);

    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Bank Account',
                'account_id' => ['iban' => 'ES1234567890'],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'link',
                    'existing_account_id' => $existingAccount->id,
                ],
            ],
        ]);

    $response->assertRedirect(route('settings.connections.index'));

    $existingAccount->refresh();
    expect($existingAccount->banking_connection_id)->toBe($connection->id);
    expect($existingAccount->external_account_id)->toBe('ext-1');
    expect($existingAccount->linked_at)->not->toBeNull();
    expect($existingAccount->iban)->toBe('ES1234567890');

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('store with action skip does nothing', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Skipped Account',
                'account_id' => [],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'skip',
                    'existing_account_id' => null,
                ],
            ],
        ]);

    $response->assertRedirect(route('settings.connections.index'));

    $this->assertDatabaseMissing('accounts', [
        'banking_connection_id' => $connection->id,
    ]);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
});

test('store with mixed actions works correctly', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create();
    $existingAccount = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'currency_code' => 'EUR',
        'banking_connection_id' => null,
    ]);

    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Account to Create',
                'account_id' => [],
            ],
            [
                'uid' => 'ext-2',
                'currency' => 'EUR',
                'name' => 'Account to Link',
                'account_id' => [],
            ],
            [
                'uid' => 'ext-3',
                'currency' => 'EUR',
                'name' => 'Account to Skip',
                'account_id' => [],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'create',
                    'existing_account_id' => null,
                ],
                [
                    'bank_account_uid' => 'ext-2',
                    'action' => 'link',
                    'existing_account_id' => $existingAccount->id,
                ],
                [
                    'bank_account_uid' => 'ext-3',
                    'action' => 'skip',
                    'existing_account_id' => null,
                ],
            ],
        ]);

    $response->assertRedirect(route('settings.connections.index'));

    // Created account exists
    $this->assertDatabaseHas('accounts', [
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-1',
    ]);

    // Linked account is updated
    $existingAccount->refresh();
    expect($existingAccount->external_account_id)->toBe('ext-2');
    expect($existingAccount->linked_at)->not->toBeNull();

    // Skipped account was not created
    $this->assertDatabaseMissing('accounts', [
        'external_account_id' => 'ext-3',
        'banking_connection_id' => $connection->id,
    ]);
});

test('validation fails when linking without existing_account_id', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'currency' => 'EUR',
                'name' => 'Test',
                'account_id' => [],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->post(route('open-banking.map-accounts.store', $connection), [
            'mappings' => [
                [
                    'bank_account_uid' => 'ext-1',
                    'action' => 'link',
                    'existing_account_id' => null,
                ],
            ],
        ]);

    $response->assertSessionHasErrors('mappings.0.existing_account_id');
});
