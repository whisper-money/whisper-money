<?php

use App\Models\User;

test('account page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('account.edit'));

    $response->assertOk();
});

test('account page contains profile information', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('account.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/account')
            ->has('mustVerifyEmail')
            ->has('twoFactorEnabled')
            ->has('requiresConfirmation')
        );
});
