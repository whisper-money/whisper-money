<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Laravel\Pennant\Feature;

test('users without budgets feature get 404 on budgets index', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertNotFound();
});

test('users with budgets feature can access budgets index', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    Feature::for($user)->activate('budgets');

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertOk();
});

test('users without budgets feature get 404 on budgets show', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertNotFound();
});

test('users without budgets feature get 404 on budgets store', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_id' => $category->id,
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertNotFound();
});

test('users without budgets feature get 404 on budgets update', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->patch("/budgets/{$budget->id}", [
        'name' => 'Updated Budget',
    ]);

    $response->assertNotFound();
});

test('users without budgets feature get 404 on budgets destroy', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->delete("/budgets/{$budget->id}");

    $response->assertNotFound();
});

test('budgets feature flag is shared with frontend when enabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    Feature::for($user)->activate('budgets');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.budgets', true)
    );
});

test('budgets feature flag is shared with frontend when disabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    Feature::for($user)->deactivate('budgets');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.budgets', false)
    );
});

test('guests see budgets feature as false', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.budgets', false)
    );
});
