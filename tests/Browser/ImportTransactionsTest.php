<?php

use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows no accounts message when none exist', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="Import transactions"]')
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});
