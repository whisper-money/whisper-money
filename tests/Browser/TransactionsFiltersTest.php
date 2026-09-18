<?php

use App\Models\SavedFilter;
use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * The transactions list beyond CRUD: the filter panel, the saved filters built
 * on top of it, paging, sorting and the analysis drawer. Creating, editing,
 * deleting and searching a transaction live in TransactionsTest.
 *
 * The panel and each of its dropdowns is a layer of its own that covers the
 * toolbar underneath, so every test here opens and closes them one at a time
 * and waits for each to be gone before reaching for what it was hiding.
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
 * Open the filter panel. The trigger is matched by shape rather than by its
 * exact text, which grows a badge with the number of active filters as soon as
 * there is one.
 */
function openTransactionFilters($page)
{
    return $page->click('button:has-text("Filters")')
        ->assertPresent('[data-test="transaction-filters-panel"]');
}

function closeTransactionFilters($page)
{
    return $page->click('button:has-text("Filters")')
        ->assertNotPresent('[data-test="transaction-filters-panel"]');
}

/**
 * Close the dropdown a filter was just picked from by clicking the panel's own
 * heading, which leaves the panel itself open.
 */
function dismissFilterDropdown($page)
{
    return $page->click('h4:has-text("Filters")')
        ->assertNotPresent('[cmdk-list]');
}

it('narrows the list to a date range and clears it again', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertSee('Hotel in Lisbon');

    // Each date lands its own visit, so the second one waits for the first to
    // come back before typing: the list is re-read from the server every time.
    openTransactionFilters($page)
        ->fill('[data-testid="filter-date-from"]', '2026-05-01')
        ->assertQueryStringHas('date_from', '2026-05-01')
        ->fill('[data-testid="filter-date-to"]', '2026-05-31')
        ->assertQueryStringHas('date_to', '2026-05-31');

    // Only May survives: April and March are out.
    $page->assertSee('Weekly groceries market')
        ->assertSee('Salary payment May')
        ->assertDontSee('Hotel in Lisbon')
        ->assertDontSee('Gym membership fee');

    closeTransactionFilters($page)
        ->click('button:has-text("Clear")')
        ->assertSee('Hotel in Lisbon')
        ->assertSee('Gym membership fee')
        ->assertQueryStringMissing('date_from')
        ->assertNoJavascriptErrors();
});

it('narrows the list by account, category and label', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market');

    // One account.
    openTransactionFilters($page)
        ->click('[data-testid="filter-accounts"]')
        ->click('[cmdk-item]:has-text("Rainy Day Savings")')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Weekly groceries market');

    // That account AND one of its categories: the two filters intersect.
    dismissFilterDropdown($page)
        ->click('[data-testid="filter-categories"]')
        ->click('[cmdk-item]:has-text("Transport")')
        ->assertSee('Hotel in Lisbon')
        ->assertSee('Taxi to the airport')
        ->assertDontSee('Electricity bill May');

    // An income category on its own. The list has no income/expense toggle, so
    // picking the income category is how a user asks for what came in.
    dismissFilterDropdown($page);
    closeTransactionFilters($page)->click('button:has-text("Clear")');

    openTransactionFilters($page)
        ->click('[data-testid="filter-categories"]')
        ->click('[cmdk-item]:has-text("Salary")')
        ->assertSee('Salary payment May')
        ->assertDontSee('Weekly groceries market');

    // A label.
    dismissFilterDropdown($page);
    closeTransactionFilters($page)->click('button:has-text("Clear")');

    openTransactionFilters($page)
        ->click('[data-testid="filter-labels"]')
        ->click('[cmdk-item]:has-text("Commute")')
        ->assertSee('Monthly train pass')
        ->assertDontSee('Hotel in Lisbon')
        ->assertNoJavascriptErrors();
});

it('combines the search box with a filter', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market');

    // Two movements from two different accounts carry May in their description.
    $page->fill('input[placeholder="Search description or notes..."]', 'May')
        ->assertSee('Electricity bill May')
        ->assertSee('Salary payment May')
        ->assertDontSee('Weekly groceries market');

    // Narrowing to one account leaves a single row out of the two.
    openTransactionFilters($page)
        ->click('[data-testid="filter-accounts"]')
        ->click('[cmdk-item]:has-text("Rainy Day Savings")')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Salary payment May')
        ->assertNoJavascriptErrors();
});

it('saves the current filters, applies them after a reload, updates and deletes them', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market');

    openTransactionFilters($page)
        ->click('[data-testid="filter-labels"]')
        ->click('[cmdk-item]:has-text("Holiday")')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    dismissFilterDropdown($page);
    closeTransactionFilters($page)
        ->click('[aria-label="Saved filters"]')
        ->click('[role="menuitem"]:has-text("Save as new filter")')
        ->assertSee('Give this set of filters a name')
        ->fill('input[placeholder="e.g. Japan trip, Utilities"]', 'Holiday spending')
        ->click('button:has-text("Save")')
        ->assertSee('Filter saved');

    $savedFilter = SavedFilter::query()->where('user_id', $this->fixture->user->id)->sole();

    expect($savedFilter->name)->toBe('Holiday spending')
        ->and($savedFilter->filters['label_ids'])->toBe([$this->fixture->holiday->id]);

    // A fresh visit starts unfiltered, and the saved filter narrows it again.
    $page->navigate('/transactions')
        ->assertSee('Weekly groceries market')
        ->click('[aria-label="Saved filters"]')
        ->click('[role="menuitem"]:has-text("Holiday spending")')
        ->assertNotPresent('[role="menuitem"]')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    // Changing the filters marks the saved one dirty and offers to update it.
    openTransactionFilters($page)
        ->click('[data-testid="filter-labels"]')
        ->click('[cmdk-item]:has-text("Commute")')
        ->assertSee('Monthly train pass');

    dismissFilterDropdown($page);
    closeTransactionFilters($page)
        ->click('[aria-label="Saved filters"]')
        ->click('[role="menuitem"]:has-text("Update")')
        ->assertSee('Filter updated');

    expect($savedFilter->fresh()->filters['label_ids'])
        ->toHaveCount(2)
        ->toContain($this->fixture->commute->id);

    $page->click('[aria-label="Delete saved filter"]')
        ->assertSee('This action cannot be undone')
        ->click('button:has-text("Delete")')
        ->assertNoJavascriptErrors();

    expect(SavedFilter::query()->where('user_id', $this->fixture->user->id)->exists())->toBeFalse();
});

it('loads the next page of movements on demand', function () {
    // One page of rows plus ten, so the oldest is only reachable by loading more.
    $this->fixture->addOlderMovements(52);

    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertSee(TransactionsPageFixture::PAGE_SIZE.' transactions loaded')
        ->click('button:has-text("Load more")')
        ->assertSee('60 transactions loaded')
        ->assertSee('Older movement 52')
        ->assertNoJavascriptErrors();
});

it('reorders the list from the date column header', function () {
    $page = visit('/transactions');

    // Newest first by default.
    $page->assertSee('Weekly groceries market')
        ->assertSeeIn('tr[data-index="0"]', 'Weekly groceries market');

    $page->click('th button:has-text("Date")')
        ->assertQueryStringHas('sort', 'transaction_date')
        ->assertSeeIn('tr[data-index="0"]', 'Gym membership fee')
        ->assertNoJavascriptErrors();
});

it('opens the analysis drawer over the filtered set', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market');

    // The drawer reports on whatever the filters select, and its button stays
    // inert until there is a filter to report on.
    openTransactionFilters($page)
        ->click('[data-testid="filter-labels"]')
        ->click('[cmdk-item]:has-text("Holiday")')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    dismissFilterDropdown($page);
    closeTransactionFilters($page)
        ->click('Analysis')
        ->assertPresent('[data-testid="analysis-drawer"]')
        ->assertSee('Total spent')
        // 120.00 and 34.00, over the five days the two movements span.
        ->assertSee('$154.00')
        ->assertSee('$30.80')
        ->assertSee('2 transactions')
        ->assertNoJavascriptErrors();
});
