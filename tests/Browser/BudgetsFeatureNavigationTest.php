<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Laravel\Pennant\Feature;

test('budgets menu item hidden when feature disabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->deactivate('budgets');

    $page = $this->actingAs($user)->visit('/dashboard');

    $page->assertDontSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('budgets menu item visible when feature enabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/dashboard');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user cannot access budgets page when feature disabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertNotFound();
});

test('user can navigate to budgets index page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertPathIs('/budgets')
        ->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user can view empty budgets list', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user can view budgets list with existing budgets', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Test Budget',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->assertSee('Test Budget')
        ->assertNoJavascriptErrors();
});

test('user can open create budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->click('Create Budget')
        ->wait(1)
        ->assertSee('Create Budget')
        ->assertNoJavascriptErrors();
});

test('user can view a specific budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'My Monthly Budget',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('My Monthly Budget')
        ->click('View Details')
        ->wait(2)
        ->assertPathIs("/budgets/{$budget->id}")
        ->assertSee('My Monthly Budget')
        ->assertNoJavascriptErrors();
});

test('user can open edit budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Original Name',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Original Name')
        ->click('Edit budget')
        ->wait(1)
        ->assertSee('Edit Budget')
        ->assertNoJavascriptErrors();
});

test('user can open delete budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Budget to Delete',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Budget to Delete')
        ->click('//button[@aria-label="More options"]')
        ->wait(0.5)
        ->click('Delete')
        ->wait(1)
        ->assertSee('Delete Budget')
        ->assertNoJavascriptErrors();
});

test('user cannot access another users budget', function () {
    $user1 = User::factory()->create(['onboarded_at' => now()]);
    $user2 = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user1)->activate('budgets');
    Feature::for($user2)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user1->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user1->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user2)->get("/budgets/{$budget->id}");

    $response->assertForbidden();
});

test('budgets navigation works from sidebar', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/dashboard');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('budgets page shows correct feature flag state', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});
