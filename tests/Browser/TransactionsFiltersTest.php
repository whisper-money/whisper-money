<?php

use App\Models\SavedFilter;
use Tests\Support\TransactionsPageFixture;

use function Pest\Laravel\actingAs;

/**
 * The transactions list beyond CRUD: the filters toolbar and its chips, the
 * saved views built on top of them, the page's header actions, paging, the
 * order of the rows, the summary of a filtered set and the analysis drawer.
 * Creating, editing, deleting and searching a transaction live in
 * TransactionsTest.
 *
 * Every popover here is a layer of its own that covers the toolbar under it,
 * so the tests close one (by clicking the page's title) and wait for it to be
 * gone before reaching for what it was hiding.
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
 * Pick a facet from "+ Filter", which swaps the menu for that facet's editor
 * in the same popover.
 */
function addTransactionFilter($page, string $facet)
{
    return $page->click('[data-testid="add-filter-button"]')
        ->click('[data-testid="add-filter-popover"] [role="menuitem"]:has-text("'.$facet.'")');
}

/** Close whichever filter popover is open by clicking the page's title. */
function closeFilterPopover($page)
{
    return $page->click('h1:has-text("Transactions")')
        ->assertNotPresent('[data-slot="popover-content"]');
}

it('steps through the period and picks a custom range', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertSee('Hotel in Lisbon')
        ->assertSeeIn('[data-testid="period-stepper"]', 'All dates');

    // Each date lands its own visit, so the second one waits for the first to
    // come back before typing: the list is re-read from the server every time.
    $page->click('[data-testid="period-stepper-label"]')
        ->fill('[data-testid="filter-date-from"]', '2026-05-01')
        ->assertQueryStringHas('date_from', '2026-05-01')
        ->fill('[data-testid="filter-date-to"]', '2026-05-31')
        ->assertQueryStringHas('date_to', '2026-05-31');

    // Only May survives: April and March are out.
    closeFilterPopover($page)
        ->assertSeeIn('[data-testid="period-stepper"]', 'May 2026')
        ->assertSee('Weekly groceries market')
        ->assertSee('Salary payment May')
        ->assertDontSee('Hotel in Lisbon')
        ->assertDontSee('Gym membership fee');

    // A whole month steps to the month before.
    $page->click('[aria-label="Previous period"]')
        ->assertQueryStringHas('date_from', '2026-04-01')
        ->assertQueryStringHas('date_to', '2026-04-30')
        ->assertSeeIn('[data-testid="period-stepper"]', 'April 2026')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    // "All dates" takes the date filter off again.
    $page->click('[data-testid="period-stepper-label"]')
        ->click('[data-testid="period-popover"] button:has-text("All dates")')
        ->assertQueryStringMissing('date_from')
        ->assertSee('Weekly groceries market')
        ->assertSee('Gym membership fee')
        ->assertNoJavascriptErrors();
});

it('narrows the list by account, category, label and type with chips', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertMissing('[data-testid="filter-chips"]');

    // One account.
    addTransactionFilter($page, 'Account')
        ->click('[cmdk-item]:has-text("Rainy Day Savings")')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Weekly groceries market');

    // That account AND one of its categories: the two filters intersect.
    closeFilterPopover($page);
    addTransactionFilter($page, 'Category')
        ->click('[cmdk-item]:has-text("Transport")')
        ->assertSee('Hotel in Lisbon')
        ->assertSee('Taxi to the airport')
        ->assertDontSee('Electricity bill May');

    closeFilterPopover($page)
        ->assertSeeIn('[data-testid="filter-chip-account"]', 'Rainy Day Savings')
        ->assertSeeIn('[data-testid="filter-chip-category"]', 'Transport');

    // The chip reopens its editor: swapping the category there. Unticking the
    // last one would take the filter, and its chip, off.
    $page->click('[data-testid="filter-chip-category"] button:has-text("Transport")')
        ->click('[cmdk-item]:has-text("Utilities")')
        ->click('[cmdk-item]:has-text("Transport")')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Hotel in Lisbon');

    // Its × takes the account off alone.
    closeFilterPopover($page)
        ->click('[aria-label="Remove the Account filter"]')
        ->assertMissing('[data-testid="filter-chip-account"]')
        ->assertSee('Gym membership fee')
        ->assertDontSee('Weekly groceries market');

    // "Clear all" drops every chip.
    $page->click('button:has-text("Clear all")')
        ->assertMissing('[data-testid="filter-chips"]')
        ->assertSee('Weekly groceries market');

    // Income only, by type.
    addTransactionFilter($page, 'Type')
        ->click('[data-testid="add-filter-popover"] button:has-text("Income")')
        ->assertSee('Salary payment May')
        ->assertDontSee('Weekly groceries market');

    closeFilterPopover($page)
        ->assertSeeIn('[data-testid="filter-chip-type"]', 'Income')
        ->click('button:has-text("Clear all")');

    // A label.
    addTransactionFilter($page, 'Label')
        ->click('[cmdk-item]:has-text("Commute")')
        ->assertSee('Monthly train pass')
        ->assertDontSee('Hotel in Lisbon')
        ->assertNoJavascriptErrors();
});

it('combines the search box with a filter, "/" putting the cursor in it', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->keys('[data-testid="add-filter-button"]', '/')
        ->assertScript('document.activeElement.dataset.testid', 'transaction-search');

    // Two movements from two different accounts carry May in their description.
    $page->fill('[data-testid="transaction-search"]', 'May')
        ->assertSee('Electricity bill May')
        ->assertSee('Salary payment May')
        ->assertDontSee('Weekly groceries market');

    // Narrowing to one account leaves a single row out of the two.
    addTransactionFilter($page, 'Account')
        ->click('[cmdk-item]:has-text("Rainy Day Savings")')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Salary payment May')
        ->assertNoJavascriptErrors();
});

it('saves the current filters as a view, applies it after a reload, updates and deletes it', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market');

    addTransactionFilter($page, 'Label')
        ->click('[cmdk-item]:has-text("Holiday")')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    closeFilterPopover($page)
        ->click('button:has-text("Save as view")')
        ->assertSee('Give this set of filters a name')
        ->fill('input[placeholder="e.g. Japan trip, Utilities"]', 'Holiday spending')
        ->click('[role="dialog"] button:has-text("Save")')
        ->assertSee('Filter saved');

    $savedFilter = SavedFilter::query()->where('user_id', $this->fixture->user->id)->sole();

    expect($savedFilter->name)->toBe('Holiday spending')
        ->and($savedFilter->filters['label_ids'])->toBe([$this->fixture->holiday->id]);

    // A fresh visit starts unfiltered, and the saved view narrows it again.
    $page->navigate('/transactions')
        ->assertSee('Weekly groceries market')
        ->click('button:has-text("Saved views")')
        ->click('[role="menuitem"]:has-text("Holiday spending")')
        ->assertNotPresent('[role="menuitem"]')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    // Changing the filters marks the saved one dirty and offers to update it.
    $page->click('[data-testid="filter-chip-label"] button:has-text("Holiday")')
        ->click('[cmdk-item]:has-text("Commute")')
        ->assertSee('Monthly train pass');

    closeFilterPopover($page)
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

it('keeps the page actions in the header on desktop', function () {
    $page = visit('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertPresent('[data-testid="transactions-header-actions"] [data-testid="add-transaction-button"]')
        ->assertSeeIn('[data-testid="transactions-header-actions"]', 'Categorize')
        // The old toolbar over the list is gone: no second export or analysis.
        ->assertMissing('[aria-label="Export CSV"]')
        ->assertMissing('[aria-label="Analysis"]')
        ->click('[data-testid="transactions-header-actions"] [aria-label="More actions"]')
        ->assertSee('Update categories automatically')
        ->click('[role="menuitem"]:has-text("Export CSV")')
        ->assertSee('CSV downloaded')
        ->assertNoJavascriptErrors();
});

it('leaves the account facet out of the chips toolbar on an account page', function () {
    $page = visit('/accounts/'.$this->fixture->savings->id);

    $page->assertSee('Electricity bill May')
        ->assertPresent('[data-testid="period-stepper"]')
        ->click('[data-testid="add-filter-button"]')
        ->assertPresent('[data-testid="add-filter-popover"] [role="menuitem"]:has-text("Category")')
        ->assertMissing('[data-testid="add-filter-popover"] [role="menuitem"]:has-text("Account")')
        ->assertNoJavascriptErrors();
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

it('always lists newest first under day headers, even from a bookmarked sort', function () {
    $page = visit('/transactions?sort=creditor_name');

    // The first row is the header of the newest day and the first transaction
    // sits right under it; no heading offers another order.
    $page->assertSee('Weekly groceries market')
        ->assertPresent('tr[data-index="0"][data-testid="transaction-day-header"]')
        ->assertSeeIn('tr[data-index="1"]', 'Weekly groceries market')
        ->assertMissing('th button:not([role="checkbox"])')
        ->assertNoJavascriptErrors();
});

it('sums up the filtered set and opens its analysis from there', function () {
    $page = visit('/transactions');

    // With no filter there is nothing to sum up, so no summary at all.
    $page->assertSee('Weekly groceries market')
        ->assertMissing('[aria-label="Filter summary"]')
        ->assertDontSee('View analysis');

    addTransactionFilter($page, 'Label')
        ->click('[cmdk-item]:has-text("Holiday")')
        ->assertSee('Hotel in Lisbon')
        ->assertDontSee('Weekly groceries market');

    closeFilterPopover($page)
        // 120.00 and 34.00: two expenses, their total and their average.
        ->assertSeeIn('[aria-label="Filter summary"]', 'Total spending')
        ->assertSeeIn('[aria-label="Filter summary"]', '-$154.00')
        ->assertSeeIn('[aria-label="Filter summary"]', '-$77.00')
        ->click('View analysis')
        ->assertPresent('[data-testid="analysis-drawer"]')
        ->assertSee('Total spent')
        // The same two movements, over the five days they span.
        ->assertSee('$30.80')
        ->assertSee('2 transactions')
        ->assertNoJavascriptErrors();
});

it('captions the summary of a filtered set on a phone and opens its analysis from the row below', function () {
    $url = '/transactions?label_ids='.$this->fixture->holiday->id;

    $page = visit($url)->on()->iPhone14Pro()->navigate($url);

    $page->assertSeeIn('[aria-label="Filter summary"]', '2 expenses · Holiday')
        ->assertSeeIn('[aria-label="Filter summary"]', '-$154.00')
        ->assertSeeIn('[aria-label="Filter summary"]', 'Average')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        // 16px from the screen's edges, as the framed list under it.
        ->assertScript('Math.round(document.querySelector(\'[aria-label="Filter summary"]\').getBoundingClientRect().left)', 16)
        ->click('[aria-label="Filter summary"] button')
        ->assertPresent('[data-testid="analysis-drawer"]')
        ->assertNoJavascriptErrors();
});

it('filters from the phone sheet, reaching the list only on "See N transactions"', function () {
    $page = visit('/transactions')->on()->iPhone14Pro()->navigate('/transactions');

    $page->assertSee('Weekly groceries market')
        ->assertMissing('[data-testid="filters-badge"]')
        ->assertPresent('[data-testid="period-stepper"]')
        ->click('[data-testid="open-filters-sheet"]')
        ->click('[data-testid="transaction-filters-sheet"] button:has-text("Expenses")')
        ->click('[data-testid="sheet-labels"] button:has-text("Labels")')
        ->click('[data-testid="sheet-labels"] label:has-text("Holiday")')
        // Counted for the draft, which has not reached the list yet.
        ->assertSeeIn('[data-testid="apply-filters"]', 'See 2 transactions')
        ->assertQueryStringMissing('label_ids')
        ->click('[data-testid="apply-filters"]')
        ->assertMissing('[data-testid="transaction-filters-sheet"]')
        ->assertQueryStringHas('label_ids', $this->fixture->holiday->id)
        ->assertQueryStringHas('amount_max', '0')
        ->assertDontSee('Weekly groceries market')
        // Type and label; the period has its own stepper.
        ->assertSeeIn('[data-testid="filters-badge"]', '2')
        ->assertSeeIn('[aria-label="Filter summary"]', '2 expenses · Holiday')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[aria-label="Filter summary"] button')
        ->assertPresent('[data-testid="analysis-drawer"]')
        ->assertNoJavascriptErrors();
});

it('drops the sheet draft on ✕ and keeps the page actions in the phone header', function () {
    $page = visit('/transactions')->on()->iPhone14Pro()->navigate('/transactions');

    $page->assertSee('Weekly groceries market')
        // The search lines up with the framed list, 16px from the edge.
        ->assertScript('Math.round(document.querySelector(\'[data-testid="transaction-search"]\').closest("label").getBoundingClientRect().left)', 16)
        ->click('[data-testid="open-filters-sheet"]')
        ->click('[data-testid="transaction-filters-sheet"] button:has-text("Income")')
        ->click('[data-testid="transaction-filters-sheet"] [aria-label="Close"]')
        ->assertMissing('[data-testid="transaction-filters-sheet"]')
        ->assertQueryStringMissing('amount_min')
        ->assertSee('Weekly groceries market')
        ->click('[data-testid="transactions-header-actions"] [aria-label="More actions"]')
        ->assertSee('Categorize')
        ->assertSee('Update categories automatically')
        ->click('[role="menuitem"]:has-text("Export CSV")')
        ->assertSee('CSV downloaded')
        ->assertNoJavascriptErrors();
});

it('leaves the accounts out of the phone sheet on an account page', function () {
    $url = '/accounts/'.$this->fixture->savings->id;

    $page = visit($url)->on()->iPhone14Pro()->navigate($url);

    $page->assertSee('Electricity bill May')
        ->click('[data-testid="open-filters-sheet"]')
        ->assertPresent('[data-testid="sheet-categories"]')
        ->assertMissing('[data-testid="sheet-accounts"]')
        ->click('[data-testid="sheet-categories"] button:has-text("Categories")')
        ->click('[data-testid="sheet-categories"] label:has-text("Utilities")')
        // Counted in the browser, over the account's own rows.
        ->assertSeeIn('[data-testid="apply-filters"]', 'See 2 transactions')
        ->click('[data-testid="apply-filters"]')
        ->assertSee('Electricity bill May')
        ->assertDontSee('Hotel in Lisbon')
        ->assertNoJavascriptErrors();
});
