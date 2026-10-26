<?php

use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * Below 56rem of room the transactions list turns from the table into a
 * compact list,
 * measured on the list rather than the window: beside the open sidebar a
 * laptop already gets the list.
 *
 * @see TransactionsPageFixture for the rows every test here asserts on.
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->fixture = TransactionsPageFixture::seed();

    actingAs($this->fixture->user);
});

function visitTransactionListOnAPhone(): mixed
{
    return visit('/transactions')
        ->on()
        ->iPhone14Pro()
        ->navigate('/transactions');
}

it('draws the table on a wide screen and the compact list once the sidebar leaves no room for it', function () {
    $page = visit('/transactions')->resize(1440, 900);

    $page->assertPresent('[data-testid="transaction-list-layout"][data-layout="table"]')
        ->assertPresent('tr:has-text("Weekly groceries market")')
        ->resize(1024, 800)
        ->assertPresent('[data-testid="transaction-list-layout"][data-layout="list"]')
        ->assertMissing('table')
        ->assertPresent('[data-testid="transaction-list-row"]:has-text("Weekly groceries market")')
        ->assertNoJavascriptErrors();
});

it('fits the compact list on a phone and sorts it from the menu above it', function () {
    $page = visitTransactionListOnAPhone();

    $page->assertSee('Weekly groceries market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        // Sorted by date, the rows sit under a band per day.
        ->assertPresent('[data-testid="transaction-day-header"]')
        ->click('[data-testid="transaction-sort-menu"]')
        ->click('[role="menuitemradio"]:has-text("Creditor")')
        ->assertSeeIn('[data-testid="transaction-sort-menu"]', 'Creditor')
        ->assertMissing('[data-testid="transaction-day-header"]')
        ->assertNoJavascriptErrors();
});

it('ticks a whole day from its band once rows are being picked', function () {
    $page = visitTransactionListOnAPhone();

    $page->assertSee('Weekly groceries market')
        ->assertDontSee('Select day')
        ->click('[aria-label="Select Weekly groceries market"]')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '1 selected')
        // The first day is ticked already, so the first offer is the next day.
        // Exact text: a substring match would also catch "Deselect day".
        ->assertSee('Deselect day')
        ->click('button:text-is("Select day") >> nth=0')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '2 selected')
        ->assertAttribute('[aria-label="Select Monthly train pass"]', 'aria-pressed', 'true')
        ->assertNoJavascriptErrors();
});

it('opens the edit dialog on a tap while no row is picked', function () {
    $page = visitTransactionListOnAPhone();

    $page->assertSee('Weekly groceries market')
        ->click('[data-testid="transaction-list-row"]:has-text("Weekly groceries market")')
        ->assertSee('Edit Transaction')
        ->assertMissing('[data-test="mobile-bulk-actions-bar"]')
        ->assertNoJavascriptErrors();
});

it('loads the next page of rows with the button below them', function () {
    $this->fixture->addOlderMovements(52);

    $page = visitTransactionListOnAPhone();

    $page->assertSee(TransactionsPageFixture::PAGE_SIZE.' transactions loaded')
        ->click('button:has-text("Load more")')
        ->assertSee('60 transactions loaded')
        ->assertScript('window.scrollTo(0, document.documentElement.scrollHeight) ?? true')
        ->assertSee('Older movement 52')
        ->assertNoJavascriptErrors();
});
