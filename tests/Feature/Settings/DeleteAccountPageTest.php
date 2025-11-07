<?php

use App\Models\User;

test('delete account page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('delete-account.edit'));

    $response->assertOk();
});

test('delete account page displays delete account component', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('delete-account.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/delete-account')
        );
});
