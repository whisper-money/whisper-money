<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The day headers of a list holding movements in more than one currency: each
 * day counts every movement and adds them up in the user's currency, at that
 * day's rate.
 *
 * Every day the list shows has a rate row seeded, so no conversion reaches the
 * provider fake. Rates are EUR-based, `usd => 2.0` reading as 1 EUR = 2 USD.
 *
 * - May 18: 1 EUR row and 3 USD rows at 3.0. The server sums the dollars
 *   before converting them (-30.00 USD → -10.00 EUR), where converting row by
 *   row would round each third down and land on -9.99.
 * - May 12: 1 EUR row and 1 USD row on a day whose rates hold no dollar, so
 *   the total is unknown while the count is not.
 * - May 6: 6 EUR rows and 6 USD rows at 2.0, more than a page of ten holds
 *   after the two days above it.
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->euroAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Euro Checking',
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
    ]);
    $this->dollarAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Dollar Card',
        'currency_code' => 'USD',
        'type' => AccountType::Checking,
    ]);

    seedEuroRates('2026-05-18', ['eur' => 1.0, 'usd' => 3.0]);
    seedEuroRates('2026-05-12', ['eur' => 1.0, 'gbp' => 0.5]);
    seedEuroRates('2026-05-06', ['eur' => 1.0, 'usd' => 2.0]);

    multiCurrencyMovement('Bakery in Madrid', -1000, '2026-05-18', $this->euroAccount);
    foreach (range(1, 3) as $index) {
        multiCurrencyMovement("Diner in Boston {$index}", -1000, '2026-05-18', $this->dollarAccount);
    }

    multiCurrencyMovement('Metro in Madrid', -500, '2026-05-12', $this->euroAccount);
    multiCurrencyMovement('Subway in New York', -3000, '2026-05-12', $this->dollarAccount);

    foreach (range(1, 6) as $index) {
        multiCurrencyMovement("Market stall {$index}", -100, '2026-05-06', $this->euroAccount);
        multiCurrencyMovement("Coffee cart {$index}", -200, '2026-05-06', $this->dollarAccount);
    }

    actingAs($this->user);
});

/**
 * @param  array<string, float>  $rates
 */
function seedEuroRates(string $date, array $rates): void
{
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => $date,
        'rates' => $rates,
    ]);
}

function multiCurrencyMovement(string $description, int $amount, string $date, Account $account): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => $description,
        'notes' => null,
        'amount' => $amount,
        'currency_code' => $account->currency_code,
        'transaction_date' => $date,
    ]);
}

function dayHeader(string $day): string
{
    return "[data-testid=\"transaction-day-header\"]:has-text(\"{$day}\")";
}

it('adds up each day of /transactions in the user currency, at the day rate', function () {
    // Short enough that the infinite-scroll sentinel below the first page
    // stays out of reach, so the page holds those ten rows alone.
    $page = visit('/transactions?per_page=10')->resize(1440, 600);

    $page->assertPresent('[data-testid="transaction-list-layout"][data-layout="table"]')
        ->assertSee('Bakery in Madrid')
        ->assertSee('10 transactions loaded')
        ->assertSeeIn(dayHeader('May 18'), '4 transactions')
        ->assertSeeIn(dayHeader('May 18'), '-€20.00')
        ->assertSeeIn(dayHeader('May 12'), '2 transactions')
        ->assertSeeIn(dayHeader('May 12'), '—')
        ->assertDontSeeIn(dayHeader('May 12'), '€')
        // Five of its twelve rows made the first page; the header counts them all.
        ->assertSeeIn(dayHeader('May 6'), '12 transactions')
        ->assertSeeIn(dayHeader('May 6'), '-€12.00')
        ->assertSee('10 transactions loaded')
        ->assertNoJavascriptErrors();
});

it('adds up each day of the compact list on a phone', function () {
    $page = visit('/transactions?per_page=10')->resize(390, 844);

    $page->assertPresent('[data-testid="transaction-list-layout"][data-layout="list"]')
        ->assertSee('Bakery in Madrid')
        ->assertSeeIn(dayHeader('May 18'), '-€20.00')
        ->assertSeeIn(dayHeader('May 12'), '—')
        ->assertDontSeeIn(dayHeader('May 12'), '€')
        ->assertSeeIn(dayHeader('May 6'), '-€12.00')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();
});

it('adds up each day of a dollar account in the user currency', function () {
    $page = visit("/accounts/{$this->dollarAccount->id}")->resize(1440, 900);

    $page->waitForText('Subway in New York', 10)
        ->assertDontSee('Bakery in Madrid')
        ->assertSeeIn(dayHeader('May 12'), '1 transaction')
        ->assertSeeIn(dayHeader('May 12'), '—')
        ->assertDontSeeIn(dayHeader('May 12'), '€')
        ->assertSeeIn(dayHeader('May 6'), '6 transactions')
        ->assertSeeIn(dayHeader('May 6'), '-€6.00')
        ->assertNoJavascriptErrors();
});
