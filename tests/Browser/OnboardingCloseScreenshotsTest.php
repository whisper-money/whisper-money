<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The two screens that close the redesign, at phone width.
 *
 * Step 11 is reviewed on all three of these rather than on the diff: it is a
 * list built from what the user actually has, so the only way to see whether it
 * tells the truth is to walk three different users up to it.
 */
function closeUser(): User
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

function closeAccount(User $user, string $name, bool $connected = false): Account
{
    return Account::factory()->for($user)->create([
        'name' => $name,
        'type' => 'checking',
        'currency_code' => 'EUR',
        'banking_connection_id' => $connected
            ? BankingConnection::factory()->for($user)->create(['aspsp_name' => 'BBVA'])->id
            : null,
    ]);
}

/** The five charges that never change, in each of the last three months: €210. */
function closeSubscriptions(Account $account): void
{
    $charges = [
        ['NETFLIX', 1290],
        ['SPOTIFY', 1099],
        ['GYM BOX', 3900],
        ['MOVISTAR', 5990],
        ['SEGURO SALUD', 8721],
    ];

    foreach ($charges as $index => [$merchant, $amount]) {
        foreach (range(1, 3) as $back) {
            Transaction::factory()->for($account->user)->for($account)->plaintext()->create([
                'category_id' => null,
                'creditor_name' => $merchant,
                'amount' => -$amount,
                'currency_code' => 'EUR',
                'transaction_date' => now()->startOfMonth()->subMonths($back)->addDays($index + 1),
            ]);
        }
    }
}

/**
 * The rest of the year: 74 movements a month, each month's merchants its own so
 * nothing but the subscriptions above reads as a repeat charge. Last month adds
 * up to €1,847 once the subscriptions are counted in, which is the number the
 * reveal answered the guess with and the number the target is built on.
 */
function closeYear(Account $account, int $months = 12): void
{
    foreach (range(1, $months) as $back) {
        Transaction::factory()
            ->count(74)
            ->for($account->user)
            ->for($account)
            ->plaintext()
            ->sequence(fn ($sequence): array => [
                'creditor_name' => sprintf('COMERCIO %02d-%03d', $back, $sequence->index + 1),
                // 73 charges of €22 and one of €31 come to €1,637, which the
                // five subscriptions take to €1,847.
                'amount' => $sequence->index === 0 ? -3100 : -2200,
            ])
            ->create([
                'category_id' => null,
                'currency_code' => 'EUR',
                'transaction_date' => now()->startOfMonth()->subMonths($back)->addDays(5),
            ]);
    }
}

it('captures the first target, built on the month the reveal read', function () {
    $user = closeUser();
    $account = closeAccount($user, 'Cuenta Nómina', connected: true);
    closeSubscriptions($account);
    closeYear($account);

    actingAs($user);

    visit('/onboarding?step=target')
        ->resize(430, 932)
        ->waitForText('Your first target', 10)
        // Built on what they spend, set against what they guessed in step 4.
        ->assertSee('€1,847')
        ->assertSee('€1,200')
        ->assertSee('200')
        ->assertSee('In a year')
        ->assertSee('€2,400')
        ->assertSee('Warn me before I overspend')
        ->assertSee('Set my target')
        ->assertSee('Not yet')
        ->wait(1)
        ->screenshot(filename: 'target-first')
        ->assertNoJavascriptErrors();
});

it('captures the close for the user who did all of it', function () {
    $user = closeUser();
    $account = closeAccount($user, 'Cuenta Nómina', connected: true);
    closeSubscriptions($account);
    closeYear($account);

    foreach (['Tarjeta', 'Ahorro', 'Compartida'] as $name) {
        closeAccount($user, $name, connected: true);
    }

    AutomationRule::factory()->count(28)->for($user)->create();
    $user->update(['onboarding_answers' => [...$user->onboarding_answers, 'target' => 20000]]);

    actingAs($user);

    visit('/onboarding?step=complete')
        ->resize(430, 932)
        ->waitForText('Your dashboard isn’t empty', 10)
        ->assertSee('12 months of history')
        ->assertSee('903 movements, 28 rules filing them')
        ->assertSee('4 accounts, syncing daily')
        ->assertSee('Everything new sorted from now on')
        ->assertSee('€200 a month put aside')
        ->assertSee('You came in guessing')
        ->wait(1)
        ->screenshot(filename: 'ready-full')
        ->assertNoJavascriptErrors();
});

/**
 * A file import, rules written, and the target declined. Neither the sync line
 * nor the target line may appear — the two claims this user has not earned.
 */
it('captures the close with no bank behind it and no target set', function () {
    $user = closeUser();
    $account = closeAccount($user, 'Cuenta Nómina');
    closeSubscriptions($account);
    closeYear($account, months: 6);

    AutomationRule::factory()->count(12)->for($user)->create();

    actingAs($user);

    visit('/onboarding?step=complete')
        ->resize(430, 932)
        ->waitForText('Your dashboard isn’t empty', 10)
        ->assertSee('6 months of history')
        ->assertSee('459 movements, 12 rules filing them')
        ->assertSee('1 account')
        ->assertDontSee('syncing daily')
        ->assertDontSee('put aside')
        ->wait(1)
        ->screenshot(filename: 'ready-file-only')
        ->assertNoJavascriptErrors();
});

/**
 * One balance, typed in by hand, and nothing else. The list is one line long
 * and every other claim is one this screen is not allowed to make.
 */
it('captures the close for the user who brought one balance', function () {
    $user = closeUser();
    Account::factory()->for($user)->create([
        'name' => 'Plan de pensiones',
        'type' => 'retirement',
        'currency_code' => 'EUR',
    ]);

    actingAs($user);

    visit('/onboarding?step=complete')
        ->resize(430, 932)
        ->waitForText('Your dashboard isn’t empty', 10)
        ->assertSee('1 account')
        ->assertDontSee('months of history')
        ->assertDontSee('movements')
        ->assertDontSee('sorted from now on')
        ->assertDontSee('You came in guessing')
        ->assertSee('the rest of this list fills itself in')
        ->wait(1)
        ->screenshot(filename: 'ready-minimal')
        ->assertNoJavascriptErrors();
});
