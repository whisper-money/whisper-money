<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Notification::fake();
});

it('can create an automation rule with visual builder', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Test Rule')
        ->fill('priority', '10')
        ->assertSee('Conditions')
        ->assertSee('Description')
        ->fill('input[placeholder="Value"]', 'grocery')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('Test Rule')
        ->assertSee('Test Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Test Rule',
        'priority' => 10,
    ]);
});

it('can add multiple conditions to a group', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Multi-Condition Rule')
        ->fill('priority', '5')
        ->click('Add Condition')
        ->assertSee('AND')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('Multi-Condition Rule')
        ->assertSee('Multi-Condition Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Multi-Condition Rule',
    ]);
});

it('can add multiple groups', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Multi-Group Rule')
        ->fill('priority', '3')
        ->click('Add Group')
        ->assertSee('Group 1')
        ->assertSee('Group 2')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('Multi-Group Rule')
        ->assertSee('Multi-Group Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Multi-Group Rule',
    ]);
});

it('can select different field types and operators', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Amount Rule')
        ->fill('priority', '1')
        ->click('Description')
        ->click('Amount')
        ->assertSee('greater than')
        ->click('contains')
        ->click('greater than')
        ->fill('input[type="number"]', '100')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('Amount Rule')
        ->assertSee('Amount Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Amount Rule',
    ]);
});

it('can edit an existing rule with visual builder', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $rule = $user->automationRules()->create([
        'title' => 'Original Rule',
        'priority' => 5,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Original Rule')
        ->click('button[aria-label="Actions"]')
        ->click('Edit')
        ->waitFor('dialog')
        ->assertSee('Edit Automation Rule')
        ->assertSee('grocery')
        ->fill('title', 'Updated Rule')
        ->click('Save Changes')
        ->waitFor('Updated Rule')
        ->assertSee('Updated Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'id' => $rule->id,
        'title' => 'Updated Rule',
    ]);
});

it('validates that at least one condition is required', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Invalid Rule')
        ->fill('priority', '1')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->assertSee('At least one valid condition is required')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseMissing('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Invalid Rule',
    ]);
});

it('can toggle group operators between AND and OR', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'OR Rule')
        ->fill('priority', '1')
        ->click('Add Condition')
        ->assertSee('AND')
        ->click('Conditions joined by:')
        ->assertSee('OR')
        ->fill('input[placeholder="Value"]', 'test')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('OR Rule')
        ->assertSee('OR Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'OR Rule',
    ]);
});

it('can use is empty operator for nullable fields', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/settings/automation-rules');

    $page->assertSee('Automation Rules')
        ->click('Create Rule')
        ->waitFor('dialog')
        ->fill('title', 'Empty Category Rule')
        ->fill('priority', '1')
        ->click('Description')
        ->click('Category')
        ->click('contains')
        ->click('is empty')
        ->click('Set Category')
        ->click($category->name)
        ->click('Create')
        ->waitFor('Empty Category Rule')
        ->assertSee('Empty Category Rule')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Empty Category Rule',
    ]);
});
