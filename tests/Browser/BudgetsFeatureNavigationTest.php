<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

test('user can view a specific budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'My Monthly Budget',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('My Monthly Budget', 10)
        ->click('View Details')
        ->assertPathIs("/budgets/{$budget->id}")
        ->assertSee('My Monthly Budget')
        ->assertNoJavascriptErrors();
});
