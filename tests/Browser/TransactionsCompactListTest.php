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

it('frames the compact list 16px from the edges on a phone and keeps its plain frame beside the sidebar', function () {
    $frameOf = <<<'JS'
        (() => {
            const list = document.querySelector('[data-testid="transaction-compact-list-rows"]');
            const rect = list.getBoundingClientRect();
            const style = getComputedStyle(list);
            return JSON.stringify({
                left: Math.round(rect.left),
                right: Math.round(window.innerWidth - rect.right),
                borderLeft: style.borderLeftWidth,
                borderTop: style.borderTopWidth,
                borderBottom: style.borderBottomWidth,
                topRadius: style.borderTopLeftRadius,
                bottomRadius: style.borderBottomLeftRadius,
            });
        })()
    JS;

    $page = visitTransactionListOnAPhone();
    $page->assertSee('Weekly groceries market');

    // Design D: 16px from either edge, 8px top corners, open at the bottom.
    expect(json_decode($page->script($frameOf), true))->toBe([
        'left' => 16,
        'right' => 16,
        'borderLeft' => '1px',
        'borderTop' => '1px',
        'borderBottom' => '0px',
        'topRadius' => '8px',
        'bottomRadius' => '0px',
    ]);
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth');

    // Beside the sidebar the list is compact too, but in a plain rounded box.
    $desktop = visit('/transactions')->resize(1024, 800);
    $desktop->assertPresent('[data-testid="transaction-list-layout"][data-layout="list"]');

    $framed = json_decode($desktop->script($frameOf), true);
    expect($framed['left'])->toBeGreaterThan(16)
        ->and($framed['borderLeft'])->toBe('1px')
        ->and($framed['borderBottom'])->toBe('1px')
        ->and($framed['bottomRadius'])->toBe('8px');
});

it('draws the rows in design D\'s type and greys, light and dark', function (bool $dark) {
    $styleOf = <<<'JS'
        (() => {
            const row = [...document.querySelectorAll('[data-testid="transaction-list-row"]')]
                .find((el) => el.textContent.includes('Weekly groceries market'));
            const band = document.querySelector('[data-testid="transaction-day-header"]');
            const description = [...row.querySelectorAll('button')]
                .find((el) => el.textContent === 'Weekly groceries market');
            const detail = getComputedStyle(
                [...row.querySelectorAll('div')].find((el) => el.textContent.startsWith('Groceries')),
            );
            const total = getComputedStyle(band.lastElementChild);
            return JSON.stringify({
                lineHeight: getComputedStyle(description).lineHeight,
                detailColor: detail.color,
                detailSize: detail.fontSize,
                bandHeight: Math.round(band.getBoundingClientRect().height * 10) / 10,
                totalColor: total.color,
            });
        })()
    JS;

    $page = visit('/transactions')->on()->iPhone14Pro();
    if ($dark) {
        $page = $page->inDarkMode();
    }
    $page = $page->navigate('/transactions')->assertSee('Weekly groceries market');

    $grey = $dark ? 'rgb(168, 168, 168)' : 'rgb(99, 99, 99)';
    expect(json_decode($page->script($styleOf), true))->toBe([
        'lineHeight' => '21px',
        'detailColor' => $grey,
        'detailSize' => '13px',
        'bandHeight' => 33.8,
        'totalColor' => $grey,
    ]);
})->with(['light' => false, 'dark' => true]);

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
