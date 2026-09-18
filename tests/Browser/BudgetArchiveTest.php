<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Category;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * Archiving a budget and the per-budget notification switches, the two parts of
 * a budget's life that tests/Browser/BudgetCrudTest.php does not touch.
 *
 * What archiving does to the periods is asserted in tests/Feature/BudgetTest.php;
 * here it is about the screen: the dialog explains itself, the budget turns
 * read-only and drops out of the live Planning list.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);
});

it('archives a budget from its page and folds it into the archived section', function () {
    $user = PlanningFixtures::user();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $budget = Budget::factory()->monthly()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Household budget',
    ]);

    actingAs($user);

    $page = visit("/budgets/{$budget->id}");

    $page->assertSee('Household budget')
        ->click('[aria-label="More options"]')
        ->click('[role="menuitem"]:has-text("Archive budget")')
        ->assertSee('Archive budget')
        ->assertSee('It stops counting from today. New transactions will never land in it again, not even ones dated before today.')
        ->click('[role="alertdialog"] button:has-text("Archive budget")')
        ->assertSee('Archived')
        ->assertNoJavascriptErrors();

    expect($budget->fresh()->archived_at)->not->toBeNull();

    // Read-only from here: only deleting it outright is still on offer.
    $page->click('[aria-label="More options"]')
        ->assertSee('Delete budget')
        ->assertDontSee('Edit budget')
        ->assertDontSee('Archive budget')
        ->assertNoJavascriptErrors();

    $page->navigate('/budgets')
        ->assertSee('Planning')
        ->assertDontSee('Household budget')
        ->click('button:has-text("Archived (1)")')
        ->assertSee('Household budget')
        ->assertNoJavascriptErrors();
});

it('keeps a budget notification preference after a reload', function () {
    $user = PlanningFixtures::user();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Household budget',
        'notify_on_over_limit' => false,
    ]);

    $overLimitToggle = '[aria-label="Household budget – Over limit"]';

    actingAs($user);

    $page = visit('/settings/notifications');

    $page->assertSee('Email notifications')
        ->assertSee('Household budget')
        ->assertAttribute($overLimitToggle, 'data-state', 'unchecked')
        ->click($overLimitToggle)
        ->assertAttribute($overLimitToggle, 'data-state', 'checked')
        // The checkbox is uncontrolled, so it flips locally and nothing else on
        // screen moves when the request comes back. Waiting for the network to
        // go quiet is what keeps the reload below from racing the PATCH.
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors();

    // A full reload is what proves it persisted: the state now comes from the
    // server rather than from the click.
    $page->navigate('/settings/notifications')
        ->assertAttribute($overLimitToggle, 'data-state', 'checked')
        ->assertNoJavascriptErrors();

    expect($budget->fresh()->notify_on_over_limit)->toBeTrue();
});
