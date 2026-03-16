<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('settings budgets route redirects to the budgets index page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    actingAs($user);

    $response = get(route('budgets.settings'));

    $response->assertRedirect(route('budgets.index'));
});
