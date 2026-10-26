<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The three screens of the money path that ask for a decision, at phone width.
 *
 * Every one of them is read rather than asserted into existence: what a
 * checkout screen says about the charge, and what the AI disclosure says leaves
 * the account, is the whole of what makes grouping the consent with the
 * purchase honest — so it is looked at, not just counted.
 *
 * The paywall itself is opened by AuthenticatedPagesSmokeTest and its soft and
 * former-subscriber gates are pinned in paywall.test.tsx and PayNowGatesTest.
 */
beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.refund_window_days' => 3,
        // These are the pay-now screens, so they are captured with pay-now on:
        // `SUBSCRIPTION_PAY_NOW` ships off, and off there is no charge today and
        // so no money-back row to photograph.
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.yearly.trial_days' => 0,
    ]);
});

function gateUser(): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        // The screenshots are read in English, and a Spanish number format on
        // an English screen is the test's own artefact, not the design's.
        'format_locale' => 'en-US',
        'onboarding_answers' => ['goal' => 'keep-more', 'spending_guess' => 120000],
    ]);
}

/** Enough movements for the AI gate to have a number to argue with. */
function gateHistory(User $user, int $count = 903): Account
{
    $account = Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    Transaction::factory()
        ->count($count)
        ->for($user)
        ->for($account)
        ->sequence(fn ($sequence): array => [
            'creditor_name' => sprintf('COMERCIO %03d', $sequence->index % 40),
            'amount' => -2200,
        ])
        ->create([
            'category_id' => null,
            'currency_code' => 'EUR',
            'transaction_date' => now()->subDays(20),
        ]);

    return $account;
}

it('captures the gate in front of the bank picker', function () {
    $user = gateUser();

    actingAs($user);

    visit('/onboarding?step=create-account')
        ->resize(430, 932)
        ->waitForText('Connect a bank', 10)
        ->click('Connect a bank')
        ->waitForText('Connecting a bank needs Standard', 10)
        ->assertSee('Everything you’ve done so far stays free')
        ->assertSee('€53.94')
        ->assertSee('€8.99')
        // The refund window, in the one colour this interface spends.
        ->assertSee('3 days to change your mind')
        ->assertSee('Refunded in full, banks disconnected, imported data kept')
        // The disclosure that earns the right to switch the AI on with the plan.
        ->assertSee('Automatic sorting comes on with it')
        ->assertSee('never your balance, your name or your account number')
        ->assertSee('Start Standard — €53.94 today')
        ->assertSee('Not now — I’ll add accounts by hand')
        ->assertSee('Charged today. Full refund from Settings within 3 days.')
        ->assertScript(ANIMATIONS_SETTLED)
        ->screenshot(filename: 'gate-bank')
        ->assertNoJavascriptErrors();
});

/**
 * Step 8, for the user who came the manual way. Anyone who connected a bank
 * paid at the other gate and never sees this one.
 */
it('captures the gate in front of the AI', function () {
    $user = gateUser();
    gateHistory($user);

    actingAs($user);

    visit('/onboarding?step=ai-suggestions')
        ->resize(430, 932)
        ->waitForText('AI sorting needs Standard', 15)
        ->assertSee('It reads all 903 movements')
        ->assertSee('What leaves your account')
        ->assertSee('never your balance, your name or your account number')
        ->assertSee('3 days to change your mind')
        ->assertSee('Or keep sorting by hand')
        ->assertSee('I’ll sort them myself')
        ->assertScript(ANIMATIONS_SETTLED)
        ->screenshot(filename: 'gate-ai')
        ->assertNoJavascriptErrors();
});

/**
 * The one irreversible thing the paywall offers, and the only screen in this
 * set that is not selling anything.
 */
it('captures the free plan confirmation, with the banks named', function () {
    $user = User::factory()->onboarded()->create([
        'format_locale' => 'en-US',
        'paywall_seen_at' => now(),
        'onboarded_at' => now()->subMonths(2),
    ]);

    $account = gateHistory($user, 903);
    $connection = BankingConnection::factory()->for($user)->create(['aspsp_name' => 'BBVA']);
    $account->update(['banking_connection_id' => $connection->id]);

    actingAs($user);

    visit('/subscribe/free-plan')
        ->resize(430, 932)
        ->waitForText('This disconnects your banks', 10)
        ->assertSee('cuts your BBVA connections')
        ->assertSee('You keep')
        ->assertSee('903 movements, categories, rules, budgets')
        ->assertSee('You lose')
        ->assertSee('Nothing is deleted.')
        ->assertSee('Disconnect and continue free')
        ->assertSee('Keep my banks')
        ->assertScript(ANIMATIONS_SETTLED)
        ->screenshot(filename: 'free-plan-confirm')
        ->assertNoJavascriptErrors();
});
