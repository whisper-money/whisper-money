<?php

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
