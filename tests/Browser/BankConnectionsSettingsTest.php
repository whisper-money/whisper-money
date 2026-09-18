<?php

declare(strict_types=1);

use App\Contracts\BankingProviderInterface;
use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeBankingProvider;

use function Pest\Laravel\actingAs;

/**
 * The connections settings screen and the manage-accounts screen behind it:
 * disconnecting, syncing on demand, updating an API key, connecting a broker and
 * moving an account in and out of a connection.
 *
 * Connecting a bank from onboarding or settings, the lost-session return screen
 * and reconnecting an expired connection live in
 * tests/Browser/BankConnectionFlowTest.php and are not repeated here.
 *
 * @see FakeBankingProvider
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    app()->instance(BankingProviderInterface::class, new FakeBankingProvider);
});

/**
 * An onboarded user sitting on the connections screen.
 */
function connectionsUser(): User
{
    return User::factory()->onboarded()->create(['email_verified_at' => now()]);
}

/**
 * A live EnableBanking connection with a fixed name, so assertions can name it.
 *
 * @param  array<string, mixed>  $attributes
 */
function connectionsBankConnection(User $user, array $attributes = []): BankingConnection
{
    return connectionsConnectionFactory($user)->create($attributes);
}

/**
 * The factory behind {@see connectionsBankConnection()}, for the tests that need
 * to add a named factory state of their own.
 *
 * @return Factory<BankingConnection>
 */
function connectionsConnectionFactory(User $user)
{
    return BankingConnection::factory()->for($user)->state([
        'provider' => 'enablebanking',
        'aspsp_name' => 'Banco de Sabadell',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Active,
    ]);
}

/**
 * An account fed by the connection. Checking, so the manage-accounts screen
 * accepts it as a sync destination.
 */
function connectionsAccount(User $user, BankingConnection $connection, string $name, string $externalId): Account
{
    return Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => $externalId,
        'name' => $name,
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);
}

/**
 * Every Wise endpoint a connect or an inline sync may touch, answered with one
 * personal profile holding a EUR and a USD wallet and no activity.
 */
function connectionsFakeWise(bool $validToken = true): void
{
    Http::fake(function (Request $request) use ($validToken) {
        $url = $request->url();

        if (str_contains($url, '/v1/profiles/')) {
            return Http::response(['activities' => [], 'cursor' => null]);
        }

        if (str_contains($url, '/v1/profiles')) {
            return $validToken
                ? Http::response([['id' => 36875276, 'type' => 'personal', 'details' => []]])
                : Http::response(['error' => 'unauthorized'], 401);
        }

        if (str_contains($url, '/v2/borderless-accounts')) {
            return Http::response([[
                'id' => 44333087,
                'profileId' => 36875276,
                'balances' => [
                    ['currency' => 'EUR', 'amount' => ['value' => 19.81]],
                    ['currency' => 'USD', 'amount' => ['value' => 4.5]],
                ],
            ]]);
        }

        return Http::response([], 404);
    });
}

/**
 * The actions menu on a connection card.
 *
 * Scoped to the card on purpose: the app layout carries dropdown triggers of its
 * own (the sidebar account menu, the page header), and Playwright runs selectors
 * in strict mode, so the bare trigger selector matches three elements and throws.
 * A test that renders more than one card has to narrow this further.
 */
const CONNECTIONS_MENU = '[data-slot="card"] [data-slot="dropdown-menu-trigger"]';

it('disconnects a bank and keeps its accounts as manual ones', function () {
    $user = connectionsUser();
    $connection = connectionsBankConnection($user);
    $account = connectionsAccount($user, $connection, 'Sabadell Main', 'ext-keep-1');

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Banco de Sabadell')
        ->assertSee('1 account')
        ->click(CONNECTIONS_MENU)
        ->click('Disconnect')
        ->assertSee('Disconnect Bank')
        ->assertSee('This connection has 1 associated account(s).')
        ->click('Keep accounts')
        ->click('[role="dialog"] button:has-text("Disconnect")')
        ->assertSee('No bank connections yet.')
        ->assertDontSee('Banco de Sabadell')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->trashed())->toBeTrue();

    $account->refresh();
    expect($account->trashed())->toBeFalse()
        ->and($account->banking_connection_id)->toBeNull()
        ->and($account->external_account_id)->toBeNull();

    // The account survives as a manual one: still listed, no longer flagged as
    // fed by a bank.
    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])
        ->assertSee('Sabadell Main')
        ->assertNotPresent('[aria-label="Connected account"]')
        ->assertNoJavascriptErrors();
});

it('only deletes the accounts once the confirmation text matches', function () {
    $user = connectionsUser();
    $connection = connectionsBankConnection($user);
    $account = connectionsAccount($user, $connection, 'Sabadell Main', 'ext-delete-1');
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Banco de Sabadell')
        ->click(CONNECTIONS_MENU)
        ->click('Disconnect')
        ->assertSee('Disconnect Bank')
        ->click('Delete accounts')
        ->assertSee('to confirm:')
        // The wrong words leave the connection exactly where it was.
        ->fill('#confirmation', 'delete everything')
        ->assertButtonDisabled('[role="dialog"] button:has-text("Disconnect")')
        ->fill('#confirmation', 'delete all')
        ->assertButtonEnabled('[role="dialog"] button:has-text("Disconnect")')
        ->click('[role="dialog"] button:has-text("Disconnect")')
        ->assertSee('No bank connections yet.')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->trashed())->toBeTrue()
        ->and(Account::withTrashed()->find($account->id)->trashed())->toBeTrue()
        ->and(Transaction::withTrashed()->find($transaction->id)->trashed())->toBeTrue();
});

it('syncs a connection on demand and shows the new last-synced time', function () {
    $user = connectionsUser();
    // A last sync in another year, and no expiry date beside it, so the only
    // date on the card is the one the sync has to move.
    $connection = connectionsBankConnection($user, [
        'last_synced_at' => '2020-01-15 09:00:00',
        'valid_until' => null,
    ]);
    connectionsAccount($user, $connection, 'Sabadell Main', 'ext-sync-1');

    actingAs($user);

    $page = visit('/settings/connections');

    // QUEUE_CONNECTION=sync, so the job runs inside the request against the
    // faked provider and the card comes back with a fresh timestamp. What the
    // sync imports is the job's own business - the fake serves no movements -
    // so this covers the button, the run and the timestamp on screen, not the
    // contents of a sync.
    $page->assertSee('Active')
        ->assertSee('2020')
        ->click(CONNECTIONS_MENU)
        ->click('Sync Now')
        ->assertSee('Sync started. Transactions will be updated shortly.')
        ->assertDontSee('2020')
        ->assertSee((string) now()->year)
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->last_synced_at->isToday())->toBeTrue();
});

it('offers no manual sync while the bank has told us to back off', function () {
    $user = connectionsUser();
    // The factory's own named state, so the fixture cannot drift from the shape
    // a rate limited connection really has in production.
    $connection = connectionsConnectionFactory($user)->rateLimited()->create();
    connectionsAccount($user, $connection, 'Sabadell Main', 'ext-backoff-1');

    actingAs($user);

    $page = visit('/settings/connections');

    // The action is withheld rather than disabled: spending an access call the
    // bank has already refused only buys another refusal.
    $page->assertSee('Waiting for the bank')
        ->assertSee('Your bank limits how often we can fetch your data. We will retry automatically.')
        ->assertSee('Next attempt')
        ->click(CONNECTIONS_MENU)
        ->assertSee('Disconnect')
        ->assertDontSee('Sync Now')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->last_synced_at)->toBeNull();
});

it('offers reconnect instead of sync on an expired connection', function () {
    $user = connectionsUser();
    $connection = BankingConnection::factory()->expired()->for($user)->create([
        'provider' => 'enablebanking',
        'aspsp_name' => 'Banco de Sabadell',
        'aspsp_country' => 'ES',
    ]);
    connectionsAccount($user, $connection, 'Sabadell Main', 'ext-expired-1');

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Expired')
        ->assertSee('Reconnect')
        ->click(CONNECTIONS_MENU)
        ->assertSee('Disconnect')
        ->assertDontSee('Sync Now')
        ->assertNoJavascriptErrors();
});

it('warns that a consent is expiring soon and renews it from the notice', function () {
    $user = connectionsUser();
    // Inside the 7-day warning window in lib/banking-connections.ts.
    $connection = connectionsBankConnection($user, ['valid_until' => now()->addDays(3)]);

    // The IBANs the fake provider hands back, so the reconnect re-matches these
    // accounts instead of duplicating them.
    foreach (['ES1800810602610001111120', 'ES6200810602620003333338'] as $index => $iban) {
        Account::factory()->for($user)->create([
            'banking_connection_id' => $connection->id,
            'external_account_id' => 'ext-expiring-'.$index,
            'iban' => $iban,
            'currency_code' => 'EUR',
            'type' => AccountType::Checking,
        ]);
    }

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Expiring soon')
        ->assertSee('Your bank only grants access for a limited time, and this permission runs out shortly.')
        ->click('Reconnect')
        // The renewal leaves the app for the bank and comes back through the
        // callback, so the state of the card is the signal, not the toast: that
        // one has auto-dismissed by the time the round trip finishes.
        ->assertDontSee('Expiring soon')
        ->assertSee('Active')
        ->assertNoJavascriptErrors();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->valid_until->isAfter(now()->addDays(BankingConnection::EXPIRY_WARNING_DAYS)))->toBeTrue()
        ->and($connection->accounts()->count())->toBe(2);
});

it('updates the API token of a broker connection whose credentials were rejected', function () {
    connectionsFakeWise();

    $user = connectionsUser();
    $connection = BankingConnection::factory()->wise()->error()->for($user)->create([
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);
    // A wallet the resumed sync can land a balance on, so this proves the new
    // token is actually used rather than only stored.
    $wallet = connectionsAccount($user, $connection, 'Wise Personal EUR', '36875276:EUR');

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Wise')
        ->assertSee('Error')
        ->assertSee('Authentication failed. Your credentials may have expired or been revoked.')
        ->click('Update Credentials')
        ->assertSee('Enter your new API credentials for Wise.')
        ->fill('#update-wise-api_token', 'new-valid-wise-token-12345')
        ->click('[role="dialog"] button:has-text("Update Credentials")')
        ->assertSee('Credentials updated. Sync started.')
        ->assertSee('Active')
        ->assertDontSee('Enter your new API credentials for Wise.')
        ->assertNoJavascriptErrors();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->error_message)->toBeNull()
        ->and($connection->api_token)->toBe('new-valid-wise-token-12345')
        // 19.81 EUR, the balance the faked Wise account holds.
        ->and($wallet->balances()->latest('balance_date')->value('balance'))->toBe(1981);
});

it('updates the key and the secret of an exchange connection', function () {
    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0']],
        ]),
        '*' => Http::response([], 404),
    ]);

    $user = connectionsUser();
    $connection = BankingConnection::factory()->binance()->error()->for($user)->create([
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Binance')
        ->click('Update Credentials')
        ->assertSee('Enter your new API credentials for Binance.')
        ->fill('#update-binance-api_key', 'new-valid-binance-key-12345')
        ->fill('#update-binance-api_secret', 'new-valid-binance-secret-12345')
        ->click('[role="dialog"] button:has-text("Update Credentials")')
        ->assertSee('Credentials updated. Sync started.')
        ->assertSee('Active')
        ->assertNoJavascriptErrors();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->api_token)->toBe('new-valid-binance-key-12345')
        ->and($connection->api_secret)->toBe('new-valid-binance-secret-12345');
});

it('keeps the credentials dialog open when the provider rejects the new token', function () {
    connectionsFakeWise(validToken: false);

    $user = connectionsUser();
    $connection = BankingConnection::factory()->wise()->error()->for($user)->create([
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    actingAs($user);

    $page = visit('/settings/connections');

    $page->click('Update Credentials')
        ->assertSee('Enter your new API credentials for Wise.')
        ->fill('#update-wise-api_token', 'still-invalid-wise-token-12345')
        ->click('[role="dialog"] button:has-text("Update Credentials")')
        ->assertSee('Invalid credentials. Please check and try again.')
        ->assertSee('Enter your new API credentials for Wise.')
        ->assertNoJavascriptErrors();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error)
        ->and($connection->api_token)->not->toBe('still-invalid-wise-token-12345');
});

it('connects a broker with an API key from settings', function () {
    connectionsFakeWise();

    $user = connectionsUser();

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Bank Connections')
        ->click('Connect Bank')
        ->click('[role="dialog"] [role="combobox"]')
        ->click('[role="option"]:has-text("Spain")')
        ->click('[role="dialog"] button:has-text("Continue")')
        // Wise is offered beside the banks, from CONNECT_PROVIDERS.
        ->click('[role="dialog"] button:has-text("Wise")')
        ->click('[role="dialog"] button:has-text("Continue")')
        ->assertSee('Enter your Wise Personal API token to connect your account.')
        ->fill('#connect-wise-api_token', 'valid-wise-api-token-12345')
        ->click('[role="dialog"] button:has-text("Connect")')
        // An onboarded user maps the wallets the token turned up before anything
        // is created.
        ->assertSee('Map Bank Accounts');

    $connection = $user->bankingConnections()->sole();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping)
        ->and($connection->pending_accounts_data)->toHaveCount(2)
        ->and($connection->accounts()->count())->toBe(0);

    $page->click('button:has-text("Save & Sync")')
        ->assertSee('Bank account connected successfully.')
        ->assertSee('Wise')
        ->assertSee('2 accounts')
        ->assertSee('Active')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->accounts()->pluck('external_account_id')->sort()->values()->all())
        ->toBe(['36875276:EUR', '36875276:USD']);

    // The first sync ran on the way out, so each wallet carries the balance the
    // faked Wise account reported: 19.81 EUR and 4.50 USD.
    $balances = $connection->accounts()
        ->get()
        ->mapWithKeys(fn (Account $account) => [
            $account->external_account_id => $account->balances()->latest('balance_date')->value('balance'),
        ]);

    expect($balances['36875276:EUR'])->toBe(1981)
        ->and($balances['36875276:USD'])->toBe(450);
});

it('stops syncing one account of a connection and links it back', function () {
    $user = connectionsUser();
    $connection = connectionsBankConnection($user);
    connectionsAccount($user, $connection, 'Sabadell Main', 'ext-manage-1');
    $second = connectionsAccount($user, $connection, 'Sabadell Savings', 'ext-manage-2');

    // The fake provider reports no accounts for an existing session, so this
    // test drives the discovery call itself: the bank still offers the account
    // that was just unlinked, which is what makes linking it back possible.
    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getSession')->andReturn([
        'status' => 'AUTHORIZED',
        'accounts' => [['uid' => 'ext-manage-1'], ['uid' => 'ext-manage-2']],
    ]);
    $provider->shouldReceive('getAccount')->with('ext-manage-2')->andReturn([
        'uid' => 'ext-manage-2',
        'account_id' => ['iban' => 'ES6200810602620003333338'],
        'currency' => 'EUR',
        'name' => 'Sabadell Savings',
    ]);
    $provider->shouldReceive('getTransactions')->andReturn(['transactions' => [], 'continuation_key' => null]);
    $provider->shouldReceive('getBalances')->andReturn(['balances' => []]);
    $provider->shouldReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $provider);

    actingAs($user);

    $page = visit("/open-banking/connections/{$connection->id}/accounts");

    $page->assertSee('Banco de Sabadell')
        ->assertSee('Sabadell Main')
        ->assertSee('Sabadell Savings')
        // The second card's menu; the first belongs to the account kept syncing.
        ->click('[data-slot="card"]:has-text("Sabadell Savings") [data-slot="dropdown-menu-trigger"]')
        ->click('Stop syncing')
        ->assertSee('Stop syncing this account?')
        ->click('[role="alertdialog"] button:has-text("Stop syncing")')
        ->assertSee('Account is no longer syncing. It is now a manual account.');

    expect($second->fresh()->banking_connection_id)->toBeNull();

    // Back through the bank's own list: the account it still offers is pointed
    // at the manual account that was just detached.
    $page->click('Load accounts')
        ->click('[role="combobox"]:has-text("Select an account")')
        ->click('[role="option"]:has-text("Sabadell Savings")')
        ->assertSee('Account synced. Transactions will be updated shortly.')
        ->assertNoJavascriptErrors();

    $second->refresh();
    expect($second->banking_connection_id)->toBe($connection->id)
        ->and($second->external_account_id)->toBe('ext-manage-2')
        ->and($connection->accounts()->count())->toBe(2);
});
