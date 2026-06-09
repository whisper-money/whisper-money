<?php

declare(strict_types=1);

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use Tests\Support\FakeBankingProvider;

use function Pest\Laravel\actingAs;

/**
 * End-to-end coverage of the Enable Banking connection flow, with the provider faked
 * so it runs deterministically in CI. The live-sandbox equivalent of these flows lives
 * in tests/Browser/live/connect-bank.mjs and runs on demand against the real sandbox.
 *
 * @see FakeBankingProvider
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->fakeProvider = new FakeBankingProvider;
    app()->instance(BankingProviderInterface::class, $this->fakeProvider);
});

it('connects a bank during onboarding', function () {
    $user = User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
    ]);

    actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Welcome to')
        ->click("Let's Get Started")
        ->waitForText('Account Types', 5)
        ->click('Create Your First Account')
        ->waitForText('How would you like to set up this account?', 5)
        ->click('Connected')
        ->click('Continue')
        ->waitForText('Connect Your Bank', 5)
        ->click('[role="combobox"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Spain")')
        ->wait(0.3)
        ->click('button:has-text("Continue")')
        ->waitForText('Banco de Sabadell', 5)
        ->click('button:has-text("Banco de Sabadell")')
        ->click('button:has-text("Continue")')
        ->waitForText('You will be redirected', 5)
        ->click('button:has-text("Connect")')
        ->wait(3);

    // The faked provider redirects straight to our callback, which auto-creates the
    // accounts for a not-yet-onboarded user and marks the connection active.
    $connection = $user->bankingConnections()->sole();

    expect($connection->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->aspsp_name)->toBe('Banco de Sabadell')
        ->and($connection->accounts()->count())->toBe(2);

    $page->assertNoJavascriptErrors();
});

it('connects a bank from settings and maps accounts', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Bank Connections')
        ->click('Connect Bank')
        ->waitForText('Select the country', 5)
        ->click('[role="dialog"] [role="combobox"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Spain")')
        ->wait(0.3)
        ->click('button:has-text("Continue")')
        ->waitForText('Banco de Sabadell', 5)
        ->click('[role="dialog"] button:has-text("Banco de Sabadell")')
        ->click('[role="dialog"] button:has-text("Continue")')
        ->waitForText('You will be redirected', 5)
        ->click('[role="dialog"] button:has-text("Connect")')
        ->wait(3);

    // An onboarded user lands on the account-mapping screen (status awaiting_mapping).
    $connection = $user->bankingConnections()->sole();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping)
        ->and($connection->pending_accounts_data)->toHaveCount(2);

    $page->assertPathBeginsWith('/open-banking/connections/')
        ->assertSee('Map Bank Accounts')
        ->click('button:has-text("Save & Sync")')
        ->wait(3);

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->accounts()->count())->toBe(2);

    $page->assertNoJavascriptErrors();
});

it('shows the connected confirmation when the session is lost on return', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);

    // A pending connection mid-authorization, identified by its state token.
    $connection = BankingConnection::factory()->pending()->for($user)->create([
        'provider' => 'enablebanking',
        'aspsp_name' => 'Banco de Sabadell',
        'aspsp_country' => 'ES',
        'state_token' => 'session-lost-token',
    ]);

    // Hit the callback WITHOUT acting as the user — this is the iOS-PWA case where the
    // bank redirect lands in a browser that has no app session.
    $page = visit('/open-banking/callback?code=fake&state=session-lost-token');

    $page->assertSee('Bank account connected')
        ->assertSee('go back to the app')
        ->assertNoJavascriptErrors();

    // The connection is still finalized server-side (resolved from the state token).
    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping)
        ->and($connection->session_id)->not->toBeNull();
});

it('reconnects an expired connection from settings', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);

    $connection = BankingConnection::factory()->expired()->for($user)->create([
        'provider' => 'enablebanking',
        'aspsp_name' => 'Banco de Sabadell',
        'aspsp_country' => 'ES',
    ]);

    // Existing accounts carry the IBANs the faked provider returns, so the reconnect
    // re-matches them by IBAN and refreshes their external ids instead of duplicating.
    foreach (['ES1800810602610001111120', 'ES6200810602620003333338'] as $iban) {
        Account::factory()->for($user)->create([
            'banking_connection_id' => $connection->id,
            'iban' => $iban,
            'currency_code' => 'EUR',
        ]);
    }

    actingAs($user);

    $page = visit('/settings/connections');

    $page->assertSee('Banco de Sabadell')
        ->assertSee('Expired')
        ->click('Reconnect')
        ->wait(3);

    $connection->refresh();

    expect($connection->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->valid_until->isFuture())->toBeTrue()
        ->and($connection->accounts()->count())->toBe(2)
        ->and($connection->accounts()->whereNull('external_account_id')->count())->toBe(0);

    $page->assertNoJavascriptErrors();
});
