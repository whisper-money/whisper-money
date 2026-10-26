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
 * The screens of the bank path no other browser test reaches, captured at
 * phone width. The picker, the handoff and the other-device return are walked
 * in {@see BankConnectionFlowTest}, the wait in OnboardingStepInteractionsTest
 * and the refusal in OnboardingFlowTest. The provider is faked, exactly as in
 * {@see BankConnectionFlowTest}, so the catalogue and the callback are
 * deterministic in CI.
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
        ->assertScript(ANIMATIONS_SETTLED)
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
        ->assertScript(ANIMATIONS_SETTLED)
        ->screenshot(filename: 'bank-which-accounts')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::AwaitingMapping);
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
        ->assertScript(ANIMATIONS_SETTLED)
        ->screenshot(filename: 'bank-taking-its-time')
        ->assertNoJavascriptErrors();
});
