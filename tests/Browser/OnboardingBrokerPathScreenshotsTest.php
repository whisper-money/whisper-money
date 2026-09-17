<?php

declare(strict_types=1);

use App\Contracts\BankingProviderInterface;
use App\Models\Account;
use App\Models\User;
use Tests\Support\FakeBankingProvider;

use function Pest\Laravel\actingAs;

/**
 * The broker path at phone width: the section under the banks, the list the hub
 * opens, and the credential form each provider ends on.
 *
 * The change is what these look like, so the PR is reviewed on them rather than
 * on the diff. The bank catalogue is faked exactly as in
 * {@see OnboardingBankPathScreenshotsTest}, so the picker behind the section is
 * deterministic in CI.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);

    app()->instance(BankingProviderInterface::class, new FakeBankingProvider);
});

/** Indexa is only offered from Spain, which is where the design places it. */
function brokerUser(): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        'format_locale' => 'es-ES',
    ]);
}

/** The hub's second state, which is the one that asks for what is missing. */
function userWithAnAccount(): User
{
    $user = brokerUser();

    Account::factory()->for($user)->create(['currency_code' => 'EUR']);

    return $user;
}

it('captures the brokers sitting under the banks in the picker', function () {
    actingAs(brokerUser());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        ->waitForText('Where do you bank?', 5)
        ->assertSee('Brokers and exchanges')
        // What each one hands over, before the user spends a screen on it.
        ->assertSee('holdings, not movements')
        ->wait(1)
        ->screenshot(filename: 'broker-section')
        ->assertNoJavascriptErrors();
});

it('captures the list the hub opens on a pension or a broker', function () {
    actingAs(userWithAnAccount());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("What's missing?", 5)
        ->click('A pension or a broker')
        ->waitForText('Which broker or fund?', 5)
        ->assertSee('Indexa Capital')
        ->assertSee('Coinbase')
        ->assertSee('Interactive Brokers')
        ->wait(1)
        ->screenshot(filename: 'broker-list')
        ->assertNoJavascriptErrors();
});

it('captures the credential form, saying no transactions are coming', function () {
    actingAs(userWithAnAccount());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("What's missing?", 5)
        ->click('A pension or a broker')
        ->waitForText('Which broker or fund?', 5)
        ->click('button:has-text("Indexa Capital")')
        ->waitForText('Connect Indexa Capital', 5)
        ->assertSee('Where to find it')
        ->assertSee('Read-only, always')
        ->assertSee('No transactions')
        ->assertSee('Stored encrypted. Revoke it from Indexa Capital whenever you like.')
        ->wait(1)
        ->screenshot(filename: 'broker-connect')
        ->assertNoJavascriptErrors();
});

// Wise is the one connector here that does hand over movements, and promising
// a user no transactions when they are coming is the same bug in reverse.
it('captures the one provider that does bring movements in', function () {
    actingAs(userWithAnAccount());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("What's missing?", 5)
        ->click('A pension or a broker')
        ->waitForText('Which broker or fund?', 5)
        ->click('button:has-text("Wise")')
        ->waitForText('Connect Wise', 5)
        ->assertSee('balance lands in your net worth')
        ->assertDontSee('No transactions')
        ->wait(1)
        ->screenshot(filename: 'broker-connect-wise')
        ->assertNoJavascriptErrors();
});
