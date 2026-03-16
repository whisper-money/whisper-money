<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view the dashboard without javascript errors', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Dashboard')
        ->assertSee('Overview of your financial health')
        ->assertNoJavascriptErrors();
});
