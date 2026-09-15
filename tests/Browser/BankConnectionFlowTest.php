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

it('connects a bank during onboarding and asks which accounts to keep', function () {
    $user = User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        // Spain is a country we connect in, so the picker opens on its banks
        // instead of asking for a country first.
        'format_locale' => 'es-ES',
    ]);

    actingAs($user);

    // Straight to the accounts step: the questions before it are not what
    // this test is about.
    $page = visit('/onboarding?step=create-account');

    $page->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        // No country step: the guess put the country beside the search box.
        ->waitForText('Where do you bank?', 5)
        ->assertSee('Beta')
        // One tap from the list to the handoff, rather than select-then-continue.
        ->click('button:has-text("Banco de Sabadell")')
        ->waitForText('You’re about to log in at Banco de Sabadell', 5)
        ->assertSee('Your password stays at your bank')
        ->assertSee('This bank is still in beta')
        ->click('button:has-text("Continue to Banco de Sabadell")')
        ->wait(3);

    // Two accounts is a decision, so nothing is created until the user makes it.
    $connection = $user->bankingConnections()->sole();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping)
        ->and($connection->aspsp_name)->toBe('Banco de Sabadell')
        ->and($connection->accounts()->count())->toBe(0);

    $page->waitForText('Banco de Sabadell gave us 2 accounts', 5)
        ->click('button:has-text("Track these 2")')
        ->wait(3);

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::Active)
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
        // Sabadell is a beta connector in the fake catalogue.
        ->assertSee('Beta')
        ->click('[role="dialog"] button:has-text("Banco de Sabadell")')
        ->click('[role="dialog"] button:has-text("Continue")')
        ->waitForText('You will be redirected', 5)
        ->assertSee('This bank is still in beta')
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

    // The page a bank redirect lands on when it picks a browser the app session
    // does not exist in. It now names the bank and carries a way back.
    $page->assertSee('Banco de Sabadell sent you here')
        ->assertSee('The connection worked')
        ->assertSee('Open Whisper')
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
