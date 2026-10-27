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

it('fits the compact list on a phone, under a band per day and with no sort menu', function () {
    $page = visitTransactionListOnAPhone();

    $page->assertSee('Weekly groceries market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertPresent('[data-testid="transaction-day-header"]')
        ->assertDontSee('Sort by')
        ->assertNoJavascriptErrors();
});

it('runs the compact list edge to edge on a phone and keeps its frame beside the sidebar', function () {
    $frameOf = <<<'JS'
        (() => {
            const list = document.querySelector('[data-testid="transaction-compact-list-rows"]');
            const rect = list.getBoundingClientRect();
            const style = getComputedStyle(list);
            return JSON.stringify({
                left: Math.round(rect.left),
                right: Math.round(rect.right),
                width: window.innerWidth,
                borderLeft: style.borderLeftWidth,
                borderTop: style.borderTopWidth,
                radius: style.borderTopLeftRadius,
            });
        })()
    JS;

    $page = visitTransactionListOnAPhone();
    $page->assertSee('Weekly groceries market');

    // A hairline where the list starts, nothing on the sides, no corners.
    $phone = json_decode($page->script($frameOf), true);
    expect($phone['left'])->toBe(0)
        ->and($phone['right'])->toBe($phone['width'])
        ->and($phone['borderLeft'])->toBe('0px')
        ->and($phone['borderTop'])->toBe('1px')
        ->and($phone['radius'])->toBe('0px');
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');

    // Beside the sidebar the list is compact too, but stays in its frame.
    $desktop = visit('/transactions')->resize(1024, 800);
    $desktop->assertPresent('[data-testid="transaction-list-layout"][data-layout="list"]');

    $framed = json_decode($desktop->script($frameOf), true);
    expect($framed['left'])->toBeGreaterThan(0)
        ->and($framed['borderLeft'])->toBe('1px')
        ->and($framed['radius'])->not->toBe('0px');
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
