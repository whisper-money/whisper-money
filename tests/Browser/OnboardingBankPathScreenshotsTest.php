<?php

declare(strict_types=1);

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Tests\Support\FakeBankingProvider;

use function Pest\Laravel\actingAs;

/**
 * The eight screens of the bank path, captured at phone width.
 *
 * The change is what they look like, so the PR is reviewed on these rather than
 * on the diff. The provider is faked, exactly as in {@see BankConnectionFlowTest},
 * so the catalogue and the callback are deterministic in CI.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);

    app()->instance(BankingProviderInterface::class, new FakeBankingProvider);
});

/** Spain is connectable, so the picker opens on its banks rather than asking. */
function spanishUser(): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        'format_locale' => 'es-ES',
    ]);
}

it('captures the bank picker with the country beside the search', function () {
    actingAs(spanishUser());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        ->waitForText('Where do you bank?', 5)
        // The count says you are searching inside one country without spelling
        // it out, and the beta pill is a reliability signal you get before you
        // choose rather than after you are stuck.
        ->assertSee('Beta')
        ->wait(1)
        ->screenshot(filename: 'bank-connect')
        ->assertNoJavascriptErrors();
});

it('captures the full country list behind the country control', function () {
    actingAs(spanishUser());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        ->waitForText('Where do you bank?', 5)
        ->click('button:has-text("España")')
        ->waitForText('Which country is the account in?', 5)
        ->assertSee('Guessed from your settings')
        ->wait(1)
        ->screenshot(filename: 'bank-country')
        ->assertNoJavascriptErrors();
});

// A user whose region we cannot connect in gets the full list as the first
// screen, which is the only case the country is still a step of its own.
it('opens on the country list when the guess finds nothing connectable', function () {
    actingAs(User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'format_locale' => 'en-US',
    ]));

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        ->waitForText('Which country is the account in?', 5)
        ->assertDontSee('Guessed from your settings')
        ->assertNoJavascriptErrors();
});

it('captures the handoff before leaving for the bank', function () {
    actingAs(spanishUser());

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText("Let's build the picture", 5)
        ->click('Connect a bank')
        ->waitForText('Where do you bank?', 5)
        ->click('button:has-text("BBVA")')
        ->waitForText('You’re about to log in at BBVA', 5)
        ->assertSee('Your password stays at your bank')
        ->assertSee('We can read, never touch')
        ->assertSee('About 40 seconds. We’ll hold your place.')
        ->wait(1)
        ->screenshot(filename: 'bank-handoff')
        ->assertNoJavascriptErrors();
});

it('captures the account chooser a bank comes back with', function () {
    $user = spanishUser();

    $connection = BankingConnection::factory()->for($user)->awaitingMapping()->create([
        'aspsp_name' => 'BBVA',
        'aspsp_logo' => null,
        'pending_accounts_data' => [
            ['uid' => 'ext-1', 'name' => 'Cuenta Nómina', 'currency' => 'EUR', 'account_id' => ['iban' => 'ES2100750000000000004417']],
            ['uid' => 'ext-2', 'name' => 'Tarjeta Crédito', 'currency' => 'EUR', 'account_id' => ['iban' => 'ES2100750000000000008802']],
            ['uid' => 'ext-3', 'name' => 'Cuenta Ahorro', 'currency' => 'EUR', 'account_id' => ['iban' => 'ES2100750000000000001120']],
            ['uid' => 'ext-4', 'name' => 'Cuenta Comunidad', 'currency' => 'EUR', 'account_id' => ['iban' => 'ES2100750000000000000093']],
        ],
    ]);

    actingAs($user);

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText('BBVA gave us 4 accounts', 5)
        ->assertSee('•••• 4417 · EUR')
        // Everything is in until the user says otherwise; leaving one out is the
        // whole point of the screen.
        ->click('button:has-text("Cuenta Comunidad")')
        ->waitForText('Track these 3', 5)
        ->wait(1)
        ->screenshot(filename: 'bank-which-accounts')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::AwaitingMapping);
});

it('captures the wait, counting what the bank has handed over', function () {
    $user = spanishUser();

    $connection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'BBVA',
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'currency_code' => 'EUR',
    ]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    foreach (range(0, 11) as $month) {
        Transaction::factory()->count(3)->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'creditor_name' => 'Merchant '.$month,
            'transaction_date' => now()->subMonths($month)->toDateString(),
        ]);
    }

    actingAs($user);

    visit('/onboarding?step=syncing')
        ->resize(430, 932)
        ->waitForText('Reading your history', 5)
        ->assertSee('Transactions read')
        ->assertSee('Places you spent')
        ->assertSee('Still going. You can leave this screen.')
        ->wait(1)
        ->screenshot(filename: 'bank-reading')
        ->assertNoJavascriptErrors();
});

// The give-up screen is five minutes of real waiting away, so the page's own
// clock is moved past the deadline instead. Only `Date.now` is patched: the poll
// timer keeps running, reads the faked clock on its next tick and stalls.
it('captures the stuck state, with a way out of it', function () {
    $user = spanishUser();

    $connection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'BBVA',
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'currency_code' => 'EUR',
    ]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    foreach (range(0, 2) as $month) {
        Transaction::factory()->count(4)->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'creditor_name' => 'Merchant '.$month,
            'transaction_date' => now()->subMonths($month)->toDateString(),
        ]);
    }

    actingAs($user);

    $page = visit('/onboarding?step=syncing')
        ->resize(430, 932)
        ->waitForText('Reading your history', 5);

    $page->script('window.Date.now = () => '.((time() + 600) * 1000).';');

    $page->waitForText('BBVA is taking its time', 10)
        ->assertSee('Already in')
        ->assertSee('12 movements')
        ->assertSee('Carry on with 3 months')
        ->assertSee('Wait for the full year')
        ->wait(1)
        ->screenshot(filename: 'bank-taking-its-time')
        ->assertNoJavascriptErrors();
});

it('captures the return from a bank that refused', function () {
    actingAs(spanishUser());

    visit('/onboarding?step=create-account&connect_error=BBVA&connect_country=ES')
        ->resize(430, 932)
        ->waitForText('BBVA didn’t let us in', 5)
        ->assertSee('Nothing was created')
        ->assertSee('Nothing was charged')
        ->assertSee('Try BBVA again')
        ->assertSee('Use a different bank')
        ->assertSee('Bring a file instead')
        ->wait(1)
        ->screenshot(filename: 'bank-connect-failed')
        ->assertNoJavascriptErrors();
});

it('captures the return that landed in another browser', function () {
    $user = spanishUser();

    BankingConnection::factory()->pending()->for($user)->create([
        'provider' => 'enablebanking',
        'aspsp_name' => 'BBVA',
        'aspsp_country' => 'ES',
        'state_token' => 'other-device-token',
    ]);

    // Deliberately not acting as the user: this is the iOS-PWA case, where the
    // bank hands the return to Safari and the app session is not there.
    visit('/open-banking/callback?code=fake&state=other-device-token')
        ->resize(430, 932)
        ->waitForText('BBVA sent you here', 5)
        ->assertSee('The connection worked')
        ->assertSee('This tab can be closed.')
        ->assertSee('Open Whisper')
        ->wait(1)
        ->screenshot(filename: 'bank-other-device')
        ->assertNoJavascriptErrors();
});
