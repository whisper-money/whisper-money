<?php

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
    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Test Rule')
        ->assertSee('Conditions')
        ->assertSee('Description')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Groceries')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2)
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

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Multi-Condition Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Add Condition')
        ->wait(0.5)
        ->assertSee('AND')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Shopping')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

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

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Multi-Group Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Add Group')
        ->wait(0.5)
        ->assertSee('Groups joined by')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Food')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

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

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Amount Rule')
        ->click('button:has-text("Description")')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Amount")')
        ->wait(1)
        // After selecting Amount, the value input changes to number type
        ->fill('input[type="number"][placeholder="Value"]', '100')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Bills')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

    $page->assertSee('Amount Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Amount Rule',
    ]);
});

it('can edit an existing rule with visual builder', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Transport');

    $page->navigate('/settings/automation-rules')->wait(2);

    // First create a rule to edit
    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Original Rule')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Transport')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

    // Now edit it
    $page->assertSee('Original Rule')
        ->click('button[aria-label="Actions"]')
        ->wait(0.5)
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Automation Rule')
        ->fill('title', 'Updated Rule')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

    $page->assertSee('Updated Rule')
        ->assertNoJavascriptErrors();
});

it('validates that at least one condition is required', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    createCategoryViaUI($page, 'Entertainment');

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Invalid Rule')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
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

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'OR Rule')
        ->fill('input[placeholder="Value"]', 'test')
        ->click('Add Condition')
        ->wait(0.5)
        ->assertSee('AND')
        ->click('[data-testid="toggle-condition-operator"]')
        ->wait(0.5)
        ->assertSee('OR')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Health')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

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

    $page->navigate('/settings/automation-rules')->wait(2);

    $page->assertSee('Automation rules settings')
        ->wait(1)
        ->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Empty Creditor Rule')
        ->click('button:has-text("Description")')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Creditor Name")')
        ->wait(1)
        // Click the operator dropdown - it shows "contains" by default for Creditor Name
        ->click('button[role="combobox"]:has-text("contains")')
        ->wait(0.5)
        ->click('[role="option"]:has-text("is empty")')
        ->wait(0.5)
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Travel')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/settings/automation-rules')->wait(1);

    $page->assertSee('Empty Creditor Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Empty Creditor Rule',
    ]);
});
