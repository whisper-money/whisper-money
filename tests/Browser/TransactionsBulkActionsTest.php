<?php

use App\Models\AutomationRule;
use App\Models\Transaction;
use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * The bar that appears once rows are ticked on the transactions list: changing
 * the category, adding and removing labels, deleting, and running the
 * automation rules again over the selection.
 *
 * @see TransactionsPageFixture for the rows every test here asserts on.
 */
beforeEach(function () {
    // Bypass the Stripe-backed subscription gate; gating itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    $this->fixture = TransactionsPageFixture::seed();

    actingAs($this->fixture->user);
});

/**
 * A rule that moves anything bought at the supermarket to Utilities — a
 * category none of the seeded rows carry, so nothing else could have set it.
 */
function seedGroceriesRule(TransactionsPageFixture $fixture): AutomationRule
{
    return AutomationRule::factory()->create([
        'user_id' => $fixture->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['groceries', ['var' => 'description']]],
        'action_category_id' => $fixture->utilities->id,
    ]);
}

it('changes the category of every selected row', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '2')
        ->click('[data-testid="bulk-category-trigger"]')
        ->click('[cmdk-item]:has-text("Utilities")')
        ->assertSee('Updated 2 transactions');

    // The rows re-render with the new category, without reloading the list.
    $page->assertSeeIn('tr:has-text("Weekly groceries market")', 'Utilities')
        ->assertSeeIn('tr:has-text("Coffee subscription")', 'Utilities')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->category_id)
        ->toBe($this->fixture->utilities->id)
        ->and($this->fixture->movementNamed('Coffee subscription')->category_id)
        ->toBe($this->fixture->utilities->id)
        // Untouched: the change stayed inside the selection.
        ->and($this->fixture->movementNamed('Monthly train pass')->category_id)
        ->toBe($this->fixture->transport->id);
});

it('extends the selection to every row matching the filters', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '1')
        ->click('[data-testid="bulk-select-all"]')
        ->assertSee('All matching filters')
        ->click('[data-testid="bulk-category-trigger"]')
        ->click('[cmdk-item]:has-text("Utilities")')
        ->assertSee('Updated 8 transactions')
        ->assertNoJavascriptErrors();

    expect(Transaction::query()
        ->where('user_id', $this->fixture->user->id)
        ->where('category_id', $this->fixture->utilities->id)
        ->count())->toBe(8);
});

it('adds a label to the selection and clears it from the rows without a reload', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->click('[data-testid="bulk-labels-trigger"]')
        ->click('[data-testid="label-option"][data-label-name="Holiday"]')
        ->assertSee('Updated 2 transactions');

    $page->assertSeeIn('tr:has-text("Weekly groceries market")', 'Holiday')
        ->assertSeeIn('tr:has-text("Coffee subscription")', 'Holiday');

    expect($this->fixture->movementNamed('Weekly groceries market')->labels)->toHaveCount(1);

    // A successful bulk change clears the selection, so removing the label
    // starts by ticking the same two rows again.
    $page->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->click('[data-testid="bulk-labels-trigger"]')
        ->click('[cmdk-item]:has-text("Remove all labels")')
        ->assertSee('Updated 2 transactions');

    // PHP-LARAVEL-952: the badges used to survive on screen until a reload.
    $page->assertDontSeeIn('tr:has-text("Weekly groceries market")', 'Holiday')
        ->assertDontSeeIn('tr:has-text("Coffee subscription")', 'Holiday')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->labels)->toHaveCount(0)
        ->and($this->fixture->movementNamed('Coffee subscription')->labels)->toHaveCount(0)
        // The label itself stays on the rows that were never selected.
        ->and($this->fixture->movementNamed('Hotel in Lisbon')->labels)->toHaveCount(1);
});

it('deletes the selected rows through the confirmation dialog', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->click('[data-test="bulk-actions-bar"] button:has-text("Delete")')
        ->assertSee('Delete Transactions')
        // The dialog asks for the sentence back before it will delete anything.
        ->fill('input[placeholder="Delete 2 Transactions"]', 'Delete 2 Transactions')
        ->click('[role="dialog"] button:has-text("Delete")')
        ->assertDontSee('Weekly groceries market')
        ->assertDontSee('Coffee subscription')
        ->assertSee('Monthly train pass')
        ->assertNoJavascriptErrors();

    expect(Transaction::query()
        ->where('user_id', $this->fixture->user->id)
        ->whereIn('description', ['Weekly groceries market', 'Coffee subscription'])
        ->exists())->toBeFalse();
});

it('runs the automation rules again over the selection', function () {
    seedGroceriesRule($this->fixture);

    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Monthly train pass") [aria-label="Select row"]')
        ->click('[data-test="bulk-actions-bar"] button:has-text("Re-evaluate rules")')
        ->assertSee('Re-evaluation complete!')
        ->assertSeeIn('tr:has-text("Weekly groceries market")', 'Utilities')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->category_id)
        ->toBe($this->fixture->utilities->id)
        // Selected too, but no rule matches it, so its category is left alone.
        ->and($this->fixture->movementNamed('Monthly train pass')->category_id)
        ->toBe($this->fixture->transport->id);
});

it('drives the selection from the keyboard', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->keys('tr:has-text("Coffee subscription") [aria-label="Select row"]', 'c')
        ->click('[cmdk-item]:has-text("Utilities")')
        ->assertSee('Updated 2 transactions');

    expect($this->fixture->movementNamed('Coffee subscription')->category_id)
        ->toBe($this->fixture->utilities->id);

    // Backspace opens the confirmation, it never deletes on its own; Escape
    // closes the dialog first and only then clears the selection.
    $trainPass = 'tr:has-text("Monthly train pass") [aria-label="Select row"]';

    $page->click($trainPass)
        ->keys($trainPass, 'Backspace')
        ->assertSee('Delete Transactions')
        ->keys('[role="dialog"]', 'Escape')
        ->assertDontSee('Delete Transactions')
        ->assertPresent('[data-test="bulk-actions-bar"]')
        ->keys($trainPass, 'Escape')
        ->assertMissing('[data-test="bulk-actions-bar"]')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Monthly train pass'))->not->toBeNull();
});

it('runs the automation rules again for a single row from its actions menu', function () {
    seedGroceriesRule($this->fixture);

    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") button:has-text("Open menu")')
        ->click('[role="menuitem"]:has-text("Re-evaluate rules")')
        ->assertSeeIn('tr:has-text("Weekly groceries market")', 'Utilities')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->category_id)
        ->toBe($this->fixture->utilities->id);
});

/**
 * On the phone there is no checkbox column: the toolbar's Select button (or a
 * long press on a row) turns taps into ticks, and the compact bar takes over.
 */
function visitTransactionsOnAPhone(): mixed
{
    return visit('/transactions')
        ->on()
        ->iPhone14Pro()
        ->navigate('/transactions');
}

it('selects rows by tapping them in selection mode on a phone', function () {
    $page = visitTransactionsOnAPhone();

    $page->assertSee('Weekly groceries market')
        ->assertMissing('[data-test="mobile-bulk-actions-bar"]')
        ->click('[data-testid="selection-mode-select"]')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', 'Tap to select')
        ->click('tr:has-text("Weekly groceries market") td:last-child')
        ->click('tr:has-text("Coffee subscription") td:last-child')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '2 selected')
        // A tap toggles the row; it does not open the edit dialog.
        ->assertDontSee('Edit Transaction')
        ->click('[data-testid="bulk-category-trigger"]')
        ->assertSee('Change category')
        ->click('[cmdk-item]:has-text("Utilities")')
        ->assertSee('Updated 2 transactions')
        // A bulk change that went through ends selection mode.
        ->assertMissing('[data-test="mobile-bulk-actions-bar"]')
        ->assertPresent('[data-testid="selection-mode-select"]')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->category_id)
        ->toBe($this->fixture->utilities->id)
        ->and($this->fixture->movementNamed('Monthly train pass')->category_id)
        ->toBe($this->fixture->transport->id);
});

it('enters selection mode on a long press and leaves it with Done on a phone', function () {
    $page = visitTransactionsOnAPhone();

    $page->assertSee('Weekly groceries market');

    $page->script(<<<'JS'
        (async () => {
            const row = [...document.querySelectorAll('tr')]
                .find((tr) => tr.textContent.includes('Weekly groceries market'));
            const { x, y } = row.getBoundingClientRect();
            const init = { bubbles: true, pointerType: 'touch', clientX: x + 20, clientY: y + 10 };
            row.dispatchEvent(new PointerEvent('pointerdown', init));
            await new Promise((resolve) => setTimeout(resolve, 600));
            row.dispatchEvent(new PointerEvent('pointerup', init));
        })()
    JS);

    $page->assertSeeIn('[data-testid="bulk-selected-count"]', '1 selected')
        ->click('[data-testid="bulk-more-actions"]')
        ->assertSee('Delete 1')
        ->click('[role="menuitem"]:has-text("Select all visible")')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '8 selected')
        ->click('[data-testid="selection-mode-done"]')
        ->assertMissing('[data-test="mobile-bulk-actions-bar"]')
        ->assertNoJavascriptErrors();
});

it('shrinks the bar to icons on one row when the content column is narrow', function () {
    $page = visit('/transactions')->resize(900, 800);

    $page->assertSee('Weekly groceries market')
        ->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->assertPresent('[data-test="bulk-actions-bar"][data-compact]')
        ->assertSeeIn('[data-testid="bulk-selected-count"]', '1')
        ->assertVisible('[data-testid="bulk-select-all"]')
        ->assertVisible('[aria-label="Re-evaluate rules"]')
        ->assertVisible('[aria-label="Delete"]')
        ->assertVisible('[aria-label="Clear selection"]')
        // One row, and inside the viewport rather than spilling off its edge.
        ->assertScript(<<<'JS'
            (() => {
                const bar = document.querySelector('[data-test="bulk-actions-bar"]').getBoundingClientRect();

                return bar.height < 60 && bar.left >= 0 && bar.right <= window.innerWidth;
            })()
        JS)
        ->click('[data-testid="bulk-labels-trigger"]')
        ->assertPresent('[cmdk-input]')
        ->assertNoJavascriptErrors();

    $page->resize(1600, 900)
        ->assertMissing('[data-test="bulk-actions-bar"][data-compact]')
        ->assertSeeIn('[data-test="bulk-actions-bar"]', 'Re-evaluate rules');
});
