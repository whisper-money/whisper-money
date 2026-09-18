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
        ->assertSee('2 transactions')
        ->click('Change category')
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
        ->assertSee('1 transaction')
        ->click('[data-testid="bulk-select-all"]')
        ->assertSee('All matching filters')
        ->click('Change category')
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
        ->click('[data-testid="label-combobox-trigger"]')
        ->click('[data-testid="label-option"][data-label-name="Holiday"]')
        ->assertSee('Updated 2 transactions');

    $page->assertSeeIn('tr:has-text("Weekly groceries market")', 'Holiday')
        ->assertSeeIn('tr:has-text("Coffee subscription")', 'Holiday');

    expect($this->fixture->movementNamed('Weekly groceries market')->labels)->toHaveCount(1);

    // A successful bulk change clears the selection, so removing the label
    // starts by ticking the same two rows again.
    $page->click('tr:has-text("Weekly groceries market") [aria-label="Select row"]')
        ->click('tr:has-text("Coffee subscription") [aria-label="Select row"]')
        ->click('[data-testid="label-combobox-trigger"]')
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
        ->click('[data-test="bulk-actions-bar"] [aria-label="More actions"]')
        ->click('[role="menuitem"]:has-text("Delete")')
        ->assertSee('Delete Transactions')
        // The dialog asks for the sentence back before it will delete anything.
        ->fill('input[placeholder="Delete 2 Transactions"]', 'Delete 2 Transactions')
        ->click('button:has-text("Delete")')
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
        ->click('[data-test="bulk-actions-bar"] [aria-label="More actions"]')
        ->click('[role="menuitem"]:has-text("Re-evaluate rules")')
        ->assertSee('Re-evaluation complete!')
        ->assertSeeIn('tr:has-text("Weekly groceries market")', 'Utilities')
        ->assertNoJavascriptErrors();

    expect($this->fixture->movementNamed('Weekly groceries market')->category_id)
        ->toBe($this->fixture->utilities->id)
        // Selected too, but no rule matches it, so its category is left alone.
        ->and($this->fixture->movementNamed('Monthly train pass')->category_id)
        ->toBe($this->fixture->transport->id);
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
