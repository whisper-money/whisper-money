<?php

use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * A row near the bottom of the window opens its menu above the pointer. The
 * menu must never slide in under the pointer: releasing the right button over
 * an item selects it, so the right click itself would run that action.
 *
 * @see TransactionsPageFixture for the rows the page draws.
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->fixture = TransactionsPageFixture::seed();
    $this->fixture->addOlderMovements(30);

    actingAs($this->fixture->user);
});

it('keeps the row menu open when right-clicking a row at the bottom of the window', function () {
    $page = visit('/transactions')->resize(1440, 900);

    $page->assertSee('Weekly groceries market');

    // Mark the lowest transaction row fully on screen (day headers have no
    // menu), so the click needs no scroll.
    $rowCenter = (int) $page->script(<<<'JS'
        (() => {
            const rows = [...document.querySelectorAll('tbody tr[data-slot="context-menu-trigger"]')]
                .filter((row) => row.getBoundingClientRect().bottom <= window.innerHeight);
            const row = rows[rows.length - 1];
            row.setAttribute('data-test', 'bottom-row');
            const rect = row.getBoundingClientRect();
            return rect.top + rect.height / 2;
        })()
    JS);

    expect($rowCenter)->toBeGreaterThan(900 - 150);

    $page->rightClick('[data-test="bottom-row"]')
        ->wait(1)
        ->assertVisible('[role="menu"]')
        ->assertSee('Re-evaluate rules')
        ->assertNoJavascriptErrors();
});
