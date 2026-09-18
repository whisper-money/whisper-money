<?php

declare(strict_types=1);

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use Tests\Support\PlanningFixtures;

use function Pest\Laravel\actingAs;

/**
 * What happens after a rule is saved: the prompt that offers to apply it to the
 * movements already in the account, the preview of what it would touch, the
 * apply itself, and deleting the rule afterwards.
 *
 * tests/Browser/AutomationRuleBuilderTest.php already covers building the rule,
 * and tests/Feature/AutomationRuleApplicationTest.php the endpoints behind it,
 * including the queued path a match count above the sync threshold takes. This
 * file walks the one a user with a handful of matches actually sees.
 */
beforeEach(function () {
    config(['subscriptions.enabled' => false]);
});

it('offers to apply a newly saved rule and categorises the transactions it matches', function () {
    $user = PlanningFixtures::user();
    $account = PlanningFixtures::account($user, 'Everyday Checking');
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    $matching = collect(['Grocery store downtown', 'Grocery store airport'])
        ->map(fn (string $description): Transaction => PlanningFixtures::transaction(
            $user,
            $account,
            $description,
            -4210,
        ));
    $untouched = PlanningFixtures::transaction($user, $account, 'Cinema tickets', -2500);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->assertSee('Create Automation Rule')
        ->fill('title', 'Groceries rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('[data-testid="action-category-select"]')
        ->click('Groceries')
        ->click('[data-testid="submit-automation-rule"]')
        // Saving the rule flashes its id, which is what opens this prompt.
        ->assertSee('Apply this rule to your existing transactions?')
        ->click('button:has-text("Review matches")')
        ->assertSee('2 matching transaction(s)')
        ->assertSee('Grocery store downtown')
        ->assertSee('Grocery store airport')
        ->assertDontSee('Cinema tickets')
        ->click('button:has-text("Apply to 2 transaction(s)")')
        ->assertSee('Rule applied to 2 transaction(s).')
        ->assertNoJavascriptErrors();

    $rule = AutomationRule::query()->where('user_id', $user->id)->sole();

    expect($rule->title)->toBe('Groceries rule')
        ->and($rule->action_category_id)->toBe($category->id);

    expect(Transaction::query()->whereIn('id', $matching->pluck('id'))->pluck('category_id')->unique()->all())
        ->toBe([$category->id]);
    expect($untouched->fresh()->category_id)->toBeNull();

    $page->navigate('/transactions')
        ->assertSee('Grocery store downtown')
        ->assertSee('Groceries')
        ->assertNoJavascriptErrors();
});

it('deletes an automation rule from the actions menu', function () {
    $user = PlanningFixtures::user();
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Groceries rule',
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => null,
    ]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Groceries rule')
        ->click('button[aria-label="Actions"]')
        ->click('[role="menuitem"]:has-text("Delete")')
        ->assertSee('Delete Automation Rule')
        ->click('[role="alertdialog"] button:has-text("Delete")')
        ->assertSee('No automation rules found.')
        ->assertNoJavascriptErrors();

    expect(AutomationRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});
