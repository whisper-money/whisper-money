<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Models\Label;
use App\Models\SavingsGoal;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * The savings-goal lifecycle as a user walks it: created from the Planning
 * page, filled by linking transactions to it, retargeted, archived and finally
 * deleted.
 *
 * The HTTP side of each step is covered in tests/Feature/SavingsGoalTest.php.
 * What only a browser proves is that the dialogs on /budgets and
 * /savings-goals/{id} reach those endpoints, and that the progress the user
 * reads afterwards is the one the server computed.
 */
beforeEach(function () {
    // Goals are not what the subscription gate is about; gating has its own
    // coverage.
    config(['subscriptions.enabled' => false]);
});

it('creates a savings goal from the planning page and opens it', function () {
    $user = PlanningFixtures::user();
    $targetDate = now()->addYear()->format('Y-m-d');

    actingAs($user);

    $page = visit('/budgets');

    $page->assertSee('Planning')
        ->click('Create')
        ->click('[role="menuitem"]:has-text("Savings Goal")')
        ->assertSee('Create Savings Goal')
        // The year cap the picker got with #963, so a mistyped 20026 never
        // leaves the browser in the first place.
        ->assertAttribute('#goal-target-date', 'max', '2100-01-01')
        // The amount goes in first: AmountInput only reports its value when it
        // loses focus, and filling the next field is what takes focus from it.
        ->fill('#goal-target', '1000')
        ->fill('#goal-target-date', $targetDate)
        ->fill('#goal-name', 'Trip to Japan')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Trip to Japan')
        ->assertSee('$1,000.00')
        ->assertNoJavascriptErrors();

    $goal = SavingsGoal::query()->where('user_id', $user->id)->sole();

    expect($goal->name)->toBe('Trip to Japan')
        ->and($goal->target_amount)->toBe(100000)
        ->and($goal->target_date->format('Y-m-d'))->toBe($targetDate);

    // The goal's own label is what transactions get tagged with later.
    expect(Label::query()->whereKey($goal->label_id)->value('name'))->toBe('Trip to Japan');

    $page->navigate('/budgets')
        ->assertSee('Trip to Japan')
        ->assertSee('0%')
        ->assertNoJavascriptErrors();
});

it('links transactions to a goal and moves its progress', function () {
    $user = PlanningFixtures::user();
    $checking = PlanningFixtures::account($user, 'Everyday Checking');
    $savings = PlanningFixtures::account($user, 'Rainy Day Savings', AccountType::Savings);

    [$goal, $label] = PlanningFixtures::savingsGoal($user, 'Trip to Japan', 100000);

    // Money leaving a checking account is a contribution...
    PlanningFixtures::transaction($user, $checking, 'Standing order to savings', -30000);
    // ...and so is money arriving in a savings account, which is the sign rule
    // #871 fixed.
    PlanningFixtures::transaction($user, $savings, 'Year end bonus put aside', 20000);
    PlanningFixtures::transaction($user, $checking, 'Weekly groceries', -4210);

    actingAs($user);

    $page = visit("/savings-goals/{$goal->id}");

    $page->assertSee('Trip to Japan')
        ->assertSee('$0.00')
        ->click('button:has-text("Link transactions")')
        ->assertSee('Standing order to savings')
        ->assertSee('Year end bonus put aside')
        ->click('[role="dialog"] label:has-text("Standing order to savings") [role="checkbox"]')
        ->click('[role="dialog"] label:has-text("Year end bonus put aside") [role="checkbox"]')
        ->assertSee('2 selected')
        ->click('[role="dialog"] button:has-text("Save changes")')
        // 300 out of the checking account plus 200 into the savings one.
        ->assertSee('$500.00')
        ->assertSee('50%')
        ->assertNoJavascriptErrors();

    expect($goal->fresh()->savedAmountInCents())->toBe(50000)
        ->and($label->transactions()->count())->toBe(2);

    // Both contributions are listed on the goal, the untagged one is not.
    $page->assertSee('Standing order to savings')
        ->assertSee('Year end bonus put aside')
        ->assertDontSee('Weekly groceries');
});

it('edits the target and archives the goal, which moves it to the archived section', function () {
    $user = PlanningFixtures::user();
    $checking = PlanningFixtures::account($user, 'Everyday Checking');

    [$goal, $label] = PlanningFixtures::savingsGoal($user, 'New kitchen', 100000);
    PlanningFixtures::transaction($user, $checking, 'Kitchen fund top-up', -25000)
        ->labels()->attach($label->id);

    actingAs($user);

    $page = visit("/savings-goals/{$goal->id}");

    $page->assertSee('New kitchen')
        ->assertSee('25%')
        ->click('[aria-label="More options"]')
        ->click('[role="menuitem"]:has-text("Edit goal")')
        ->assertSee('Edit Savings Goal')
        // Focus first: an AmountInput rewrites itself with the amount it
        // already holds the moment it gains focus, which lands on top of a
        // value typed into a field that was not focused yet.
        ->click('#edit-goal-target')
        ->fill('#edit-goal-target', '500')
        ->assertValue('#edit-goal-target', '500')
        // Blur commits the amount; the name field above it takes the focus.
        ->click('#edit-goal-name')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('50%')
        ->assertNoJavascriptErrors();

    expect($goal->fresh()->target_amount)->toBe(50000);

    $page->click('[aria-label="More options"]')
        ->click('[role="menuitem"]:has-text("Archive goal")')
        ->assertSee('Archive savings goal')
        ->assertSee('The amount saved is frozen at what it is today, whatever happens to those transactions afterwards.')
        ->click('[role="alertdialog"] button:has-text("Archive goal")')
        ->assertSee('Archived')
        // An archived goal is read-only: nothing more can be linked to it.
        ->assertDontSee('Link transactions')
        ->assertNoJavascriptErrors();

    $goal->refresh();

    expect($goal->archived_at)->not->toBeNull()
        ->and($goal->archived_saved_amount)->toBe(25000);

    $page->navigate('/budgets')
        ->assertSee('Planning')
        // It is off the live list and folded into the archived section.
        ->assertDontSee('New kitchen')
        ->click('button:has-text("Archived (1)")')
        ->assertSee('New kitchen')
        ->assertNoJavascriptErrors();
});

it('deletes an archived goal from its page', function () {
    $user = PlanningFixtures::user();

    [$goal] = PlanningFixtures::savingsGoal($user, 'Old laptop fund', 80000);
    $goal->update(['archived_at' => now(), 'archived_saved_amount' => 12000]);
    $goal->label?->delete();

    actingAs($user);

    $page = visit("/savings-goals/{$goal->id}");

    $page->assertSee('Old laptop fund')
        ->assertSee('Archived')
        ->click('[aria-label="More options"]')
        ->click('[role="menuitem"]:has-text("Delete goal")')
        ->assertSee('Delete Savings Goal')
        ->click('[role="alertdialog"] button:has-text("Delete")')
        ->assertPathIs('/budgets')
        ->assertSee('Planning')
        ->assertDontSee('Old laptop fund')
        ->assertNoJavascriptErrors();

    expect(SavingsGoal::query()->whereKey($goal->id)->exists())->toBeFalse();
});
