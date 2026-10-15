<?php

use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Notification::fake();
});

it('can create an automation rule with visual builder', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    // Create category via UI to ensure it syncs to IndexedDB
    createCategoryViaUI($page, 'Groceries');

    // Now visit automation rules page
    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Test Rule')
        ->assertSee('Conditions')
        ->assertSee('Description')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('[data-testid="action-category-select"]')
        ->click('Groceries')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule')
        ->assertSee('Test Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Test Rule',
        'priority' => 0,
    ]);
});

it('can add multiple conditions to a group', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Shopping');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Multi-Condition Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Add Condition')
        ->assertSee('AND')
        ->click('[data-testid="action-category-select"]')
        ->click('Shopping')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Multi-Condition Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Multi-Condition Rule',
    ]);
});

it('can add multiple groups', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Food');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Multi-Group Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Add Group')
        ->assertSee('Groups joined by')
        ->click('[data-testid="action-category-select"]')
        ->click('Food')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Multi-Group Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Multi-Group Rule',
    ]);
});

it('can select different field types and operators', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Bills');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Amount Rule')
        ->click('button:has-text("Description")')
        ->click('[role="option"]:has-text("Amount")')
        // After selecting Amount, the value input becomes the masked AmountInput
        ->fill('input[placeholder="Value"]', '-5')
        ->click('[data-testid="action-category-select"]')
        ->click('Bills')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Amount Rule')
        ->assertNoJavascriptErrors();

    $rule = AutomationRule::where('user_id', $user->id)->sole();

    // AmountInput speaks cents, the rule stores currency units: -5, not -500.
    // `rules_json` is persisted as a JSON string, and `equals` is the default
    // operator for Amount since this test only changes the field.
    expect(json_decode($rule->rules_json, true))
        ->toBe(['==' => [['var' => 'amount'], -5]]);
});

it('can edit an existing rule with visual builder', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Transport');

    $page->navigate('/settings/automation-rules');

    // First create a rule to edit
    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Original Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('[data-testid="action-category-select"]')
        ->click('Transport')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    // Now edit it
    $page->assertSee('Original Rule')
        ->click('button[aria-label="Actions"]')
        ->click('Edit')
        ->assertSee('Edit Automation Rule')
        ->fill('title', 'Updated Rule')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Edit Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Updated Rule')
        ->assertNoJavascriptErrors();
});

it('validates that at least one condition is required', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Entertainment');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Invalid Rule')
        ->click('[data-testid="action-category-select"]')
        ->click('Entertainment')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertSee('At least one valid condition is required')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseMissing('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Invalid Rule',
    ]);
});

it('can toggle group operators between AND and OR', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Health');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'OR Rule')
        ->fill('input[placeholder="Value"]', 'test')
        ->click('Add Condition')
        ->assertSee('AND')
        ->click('[data-testid="toggle-condition-operator"]')
        ->assertSee('OR')
        ->click('[data-testid="action-category-select"]')
        ->click('Health')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('OR Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'OR Rule',
    ]);
});

it('can use is empty operator for nullable fields', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Travel');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Empty Creditor Rule')
        ->click('button:has-text("Description")')
        ->click('[role="option"]:has-text("Creditor Name")')
        // Click the operator dropdown - it shows "contains" by default for Creditor Name
        ->click('button[role="combobox"]:has-text("contains")')
        ->click('[role="option"]:has-text("is empty")')
        ->click('[data-testid="action-category-select"]')
        ->click('Travel')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Empty Creditor Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Empty Creditor Rule',
    ]);
});

// The exception the negative operators exist for. Reopening the rule is the half
// worth testing: it only comes back as "does not contain" if parseJsonLogic
// unwraps the `!` the builder wrote.
it('can build an exception rule and reopen it with the negative operator', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Subscriptions');

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Automation rules settings')
        ->click('button:has-text("Create Rule")')
        ->fill('title', 'Exception Rule')
        ->fill('input[placeholder="Value"]', 'keyword')
        ->click('Add Condition')
        // A new group joins its conditions with OR; the exception needs AND.
        ->click('[data-testid="toggle-condition-operator"]')
        ->assertSee('AND')
        // The second condition, still on the "contains" default it was created with.
        ->click('button[role="combobox"]:has-text("contains") >> nth=1')
        ->click('[role="option"]:has-text("does not contain")')
        ->fill('input[placeholder="Value"] >> nth=1', 'visa card')
        ->click('[data-testid="action-category-select"]')
        ->click('Subscriptions')
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');

    $rule = AutomationRule::where('user_id', $user->id)->sole();

    expect(json_decode($rule->rules_json, true))->toBe(['and' => [
        ['in' => ['keyword', ['var' => 'description']]],
        ['!' => ['in' => ['visa card', ['var' => 'description']]]],
    ]]);

    $page->navigate('/settings/automation-rules');

    $page->assertSee('Exception Rule')
        ->click('button[aria-label="Actions"]')
        ->click('Edit')
        ->assertSee('Edit Automation Rule')
        ->assertSee('does not contain')
        ->assertNoJavascriptErrors();
});

/**
 * Opens the create dialog with a two-condition group: "first" then "second".
 */
function openRuleWithTwoConditions($page, string $title): void
{
    $page->click('button:has-text("Create Rule")')
        ->fill('title', $title)
        ->fill('input[placeholder="Value"]', 'first')
        ->click('Add Condition')
        ->fill('input[placeholder="Value"] >> nth=1', 'second');
}

function saveRuleWithCategory($page, string $category): void
{
    $page->click('[data-testid="action-category-select"]')
        ->click($category)
        ->click('[data-testid="submit-automation-rule"]')
        ->assertDontSee('Create Automation Rule');
}

// Order never changes what a rule matches, so all it has to do is come back the
// way it was left: it travels in rules_json's array order.
it('can move a group up with its arrow and keeps the order', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Groceries');

    $page->navigate('/settings/automation-rules');

    $page->click('button:has-text("Create Rule")')
        ->fill('title', 'Ordered Groups Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Add Group')
        ->fill('input[placeholder="Value"] >> nth=1', 'market')
        ->assertDisabled('button[aria-label="Move group 1 up"]')
        ->keys('button[aria-label="Move group 2 up"]', ['Enter']);

    // The pressed ↑ is now disabled at the top, so keyboard focus moves to ↓
    // instead of falling back to the page.
    $page->assertScript('document.activeElement.getAttribute("aria-label")', 'Move group 1 down');

    expect($page->script('document.activeElement.getAttribute("aria-label")'))
        ->toBe('Move group 1 down');

    saveRuleWithCategory($page, 'Groceries');

    $rule = AutomationRule::where('user_id', $user->id)->sole();

    expect(json_decode($rule->rules_json, true))->toBe(['or' => [
        ['in' => ['market', ['var' => 'description']]],
        ['in' => ['grocery', ['var' => 'description']]],
    ]]);

    $page->assertNoJavascriptErrors();
});

it('can drag a condition with the keyboard without closing the dialog', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Shopping');

    $page->navigate('/settings/automation-rules');

    openRuleWithTwoConditions($page, 'Dragged Rule');

    // Escape cancels a drag; it must not also close the dialog around it.
    $page->keys('button[aria-label="Drag to reorder"] >> nth=1', ['Space'])
        ->wait(0.3)
        ->keys('button[aria-label="Drag to reorder"] >> nth=1', ['Escape'])
        ->wait(0.3)
        ->assertSee('Create Automation Rule')
        ->keys('button[aria-label="Drag to reorder"] >> nth=1', ['Space'])
        ->wait(0.3)
        ->keys('button[aria-label="Drag to reorder"] >> nth=1', ['ArrowUp'])
        ->wait(0.3)
        ->keys('button[aria-label="Drag to reorder"] >> nth=1', ['Space']);

    saveRuleWithCategory($page, 'Shopping');

    $rule = AutomationRule::where('user_id', $user->id)->sole();

    expect(json_decode($rule->rules_json, true))->toBe(['or' => [
        ['in' => ['second', ['var' => 'description']]],
        ['in' => ['first', ['var' => 'description']]],
    ]]);

    $page->assertNoJavascriptErrors();
});

it('moves conditions with labelled arrows on a phone', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Bills');

    $page->navigate('/settings/automation-rules');

    $page->resize(390, 844);

    openRuleWithTwoConditions($page, 'Phone Rule');

    // Phones swap the drag handle for arrows inside a box titled with its position.
    $page->click('Add Condition')
        ->fill('input[placeholder="Value"] >> nth=2', 'third')
        ->assertSee('Condition 3')
        ->assertDisabled('button[aria-label="Move condition 1 up"]')
        ->keys('button[aria-label="Move condition 1 down"]', ['Enter']);

    // The condition is now the second one, and focus stays on its arrow so the
    // next Enter keeps moving it.
    $page->assertScript('document.activeElement.getAttribute("aria-label")', 'Move condition 2 down');

    expect($page->script('document.activeElement.getAttribute("aria-label")'))
        ->toBe('Move condition 2 down');

    saveRuleWithCategory($page, 'Bills');

    $rule = AutomationRule::where('user_id', $user->id)->sole();

    expect(json_decode($rule->rules_json, true))->toBe(['or' => [
        ['in' => ['second', ['var' => 'description']]],
        ['in' => ['first', ['var' => 'description']]],
        ['in' => ['third', ['var' => 'description']]],
    ]]);

    $page->assertNoJavascriptErrors();
});
