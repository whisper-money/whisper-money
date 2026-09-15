<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

/**
 * Step 7 at phone width: the month the user guessed at, answered.
 *
 * This is the screen the whole flow is built towards, so the PR is reviewed on
 * what it looks like rather than on the diff. The figures below are a month
 * that adds up to €1,847 against a €1,200 guess, and nine subscriptions that
 * have charged the same amount three months running.
 */
function revealUser(int $guess): User
{
    return User::factory()->notOnboarded()->create([
        'email_verified_at' => now(),
        'currency_code' => 'EUR',
        // The screenshots are read in English, and a Spanish number format on
        // an English screen is the test's own artefact, not the design's.
        'format_locale' => 'en-US',
        'onboarding_answers' => ['spending_guess' => $guess],
    ]);
}

/** What the user paid, by merchant, in minor units. */
function spend(Account $account, Carbon $month, array $byMerchant): void
{
    $day = 1;

    foreach ($byMerchant as $merchant => $amounts) {
        foreach ($amounts as $amount) {
            Transaction::factory()->for($account->user)->for($account)->plaintext()->create([
                'category_id' => null,
                'transaction_date' => $month->copy()->addDays($day++ % 27),
                'amount' => -$amount,
                'currency_code' => 'EUR',
                'creditor_name' => $merchant,
            ]);
        }
    }
}

/** The nine charges that have not moved in three months. */
const SUBSCRIPTIONS = [
    'NETFLIX' => 1299,
    'SPOTIFY' => 1099,
    'BASIC-FIT' => 3990,
    'VODAFONE' => 4500,
    'IBERDROLA' => 7820,
    'MUTUA SEGUROS' => 6240,
    'ICLOUD' => 299,
    'CANAL DE ISABEL II' => 2130,
    'FILMIN' => 799,
];

/** Everything else the month was made of, none of it big enough to be named. */
const ODDS_AND_ENDS = [
    'REPSOL' => [7150, 4800],
    'ZARA' => [8995],
    'EL CORTE INGLES' => [11560],
    'FARMACIA GOYA' => [2340],
    'BAR MANOLO' => [1850, 2200, 3100],
    'UBER' => [1420, 980],
    'DECATHLON' => [6230],
    'CARREFOUR' => [11800],
    'TELETAXI' => [1200],
    'CINESA' => [1980],
    'PANADERIA LA ESPIGA' => [840, 620],
    'CASA DEL LIBRO' => [3490],
    'IKEA' => [9650],
    'STARBUCKS' => [540, 480],
    'CORREOS' => [300],
    'SHELL' => [8450],
    'MEDIA MARKT' => [8449],
];

it('captures the answer to the guess the flow opened with', function () {
    $user = revealUser(120000);
    $account = Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    $lastMonth = now()->startOfMonth()->subMonth();

    spend($account, $lastMonth, [
        'MERCADONA' => [8940, 7620, 10210, 4430],
        'GLOVO' => [5200, 4750, 4850],
        'AMAZON' => [6400, 3100, 2600],
        ...ODDS_AND_ENDS,
    ]);

    // The same nine charges, unchanged, in each of the last three months.
    foreach ([0, 1, 2] as $back) {
        spend($account, $lastMonth->copy()->subMonths($back), array_map(
            fn (int $amount): array => [$amount],
            SUBSCRIPTIONS,
        ));
    }

    // The long tail the next step has to sort: one movement each, months back,
    // so they count as merchants without touching the month being revealed.
    Transaction::factory()
        ->count(118)
        ->for($user)
        ->for($account)
        ->plaintext()
        ->sequence(fn ($sequence): array => [
            'creditor_name' => sprintf('COMERCIO %03d', $sequence->index + 1),
            'amount' => -1000 - $sequence->index,
        ])
        ->create([
            'category_id' => null,
            'transaction_date' => $lastMonth->copy()->subMonths(5),
            'currency_code' => 'EUR',
        ]);

    actingAs($user);

    visit('/onboarding?step=reveal')
        ->resize(430, 932)
        ->waitForText('Last month', 10)
        ->assertSee('1,847')
        ->assertSee('You guessed')
        ->assertSee('MERCADONA')
        ->assertSee('Who you paid most')
        ->assertSee('Sort these 147 merchants')
        ->wait(1)
        ->screenshot(filename: 'reveal-spending')
        ->assertNoJavascriptErrors();
});

it('captures what someone with no movements gets instead', function () {
    $user = revealUser(120000);

    $pension = Account::factory()->for($user)->create([
        'name' => 'Plan de pensiones',
        'type' => 'retirement',
        'currency_code' => 'EUR',
    ]);
    $broker = Account::factory()->for($user)->connected()->create([
        'name' => 'Indexa Capital',
        'type' => 'investment',
        'currency_code' => 'EUR',
    ]);
    $mortgage = Account::factory()->for($user)->create([
        'name' => 'Hipoteca',
        'type' => 'loan',
        'currency_code' => 'EUR',
    ]);

    foreach ([[$pension, 1840000], [$broker, 1489000], [$mortgage, 205000]] as [$account, $balance]) {
        AccountBalance::factory()->for($account)->create([
            'balance_date' => now()->subDay(),
            'balance' => $balance,
        ]);
    }

    actingAs($user);

    visit('/onboarding?step=reveal')
        ->resize(430, 932)
        ->waitForText('You’re worth', 10)
        ->assertSee('€31,240')
        ->assertSee('Plan de pensiones')
        ->assertSee('Added by hand')
        ->assertSee('Connected')
        ->assertSee('Add a current account')
        ->assertSee('Continue without one')
        ->wait(1)
        ->screenshot(filename: 'reveal-assets')
        ->assertNoJavascriptErrors();
});

/**
 * The month still running, for the user whose only history is this one — a file
 * exported this week, or a bank connected on the 2nd.
 *
 * It is the one reveal with no verdict on it: six days of a month set against a
 * guess about a whole one produced "You came in €1,119 under — almost nobody
 * misses this way", which is a compliment nobody earned.
 */
it('captures the month that has not finished, without a verdict on it', function () {
    $user = revealUser(120000);
    $account = Account::factory()->for($user)->create([
        'name' => 'Cuenta Nómina',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    spend($account, now()->startOfMonth(), [
        'MERCADONA' => [4210, 3180],
        'GLOVO' => [2650],
        'BAR MANOLO' => [1850, 2200],
        'UBER' => [1420],
    ]);

    actingAs($user);

    visit('/onboarding?step=reveal')
        ->resize(430, 932)
        ->waitForText('This month so far', 10)
        ->assertSee('155')
        ->assertSee('The month isn’t over')
        ->assertDontSee('You guessed')
        ->assertSee('Who you paid most')
        ->wait(1)
        ->screenshot(filename: 'reveal-partial-month')
        ->assertNoJavascriptErrors();
});
