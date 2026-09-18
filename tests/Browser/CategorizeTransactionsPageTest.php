<?php

use App\Models\Transaction;
use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * The one-at-a-time categorizer at /transactions/categorize: the queue of
 * movements with no category, the command palette that assigns one, and the
 * skip and previous controls that move through the queue.
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->fixture = TransactionsPageFixture::seed();

    // Five movements with no category, newest first. Two share a merchant, the
    // shape the automation-rule suggestions are built around.
    $bakery = collect([
        $this->fixture->movement('Bakery visit Friday', -1850, '2026-05-22'),
        $this->fixture->movement('Bakery visit Tuesday', -2140, '2026-05-19'),
    ]);
    $this->fixture->movement('Bookshop purchase', -3600, '2026-05-15');
    $this->fixture->movement('Pharmacy pickup', -1290, '2026-05-11');
    $this->fixture->movement('Hardware store tools', -5400, '2026-05-08');

    Transaction::query()
        ->whereIn('id', $bakery->pluck('id'))
        ->update(['creditor_name' => 'Panaderia Central']);

    actingAs($this->fixture->user);
});

it('walks the uncategorized queue and assigns a category to each movement', function () {
    $page = visit('/transactions/categorize');

    $page->assertSee('Bakery visit Friday')
        ->assertSeeIn('[data-testid="remaining-count"]', '5');

    // The palette searches as you type, and picking a category brings up the
    // next movement.
    $page->fill('input[placeholder="Search categories..."]', 'Groc')
        ->click('[cmdk-item]:has-text("Groceries")')
        ->assertSee('Bakery visit Tuesday')
        ->assertSeeIn('[data-testid="remaining-count"]', '4');

    // Skipping leaves a movement uncategorized and moves on.
    $page->click('Skip')
        ->assertSee('Bookshop purchase')
        ->assertSeeIn('[data-testid="remaining-count"]', '3');

    // There is no undo for an assignment on this screen; going back is how a
    // skipped movement is picked up again.
    $page->click('Prev')
        ->assertSee('Bakery visit Tuesday')
        ->assertSeeIn('[data-testid="remaining-count"]', '4');

    $page->click('[cmdk-item]:has-text("Transport")')
        ->assertSee('Bookshop purchase');

    $page->click('[cmdk-item]:has-text("Groceries")')
        ->assertSee('Pharmacy pickup');

    $page->click('[cmdk-item]:has-text("Groceries")')
        ->assertSee('Hardware store tools');

    $page->click('[cmdk-item]:has-text("Groceries")')
        ->assertSee('All Done!')
        ->assertSee("You've categorized all your transactions.")
        ->assertNoJavascriptErrors();

    // Every assignment landed on the movement that was on screen at the time.
    expect($this->fixture->movementNamed('Bakery visit Friday')->category_id)
        ->toBe($this->fixture->groceries->id)
        ->and($this->fixture->movementNamed('Bakery visit Tuesday')->category_id)
        ->toBe($this->fixture->transport->id)
        ->and($this->fixture->movementNamed('Bookshop purchase')->category_id)
        ->toBe($this->fixture->groceries->id)
        ->and($this->fixture->movementNamed('Pharmacy pickup')->category_id)
        ->toBe($this->fixture->groceries->id)
        ->and($this->fixture->movementNamed('Hardware store tools')->category_id)
        ->toBe($this->fixture->groceries->id);
});
