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
            ->where('hasActiveSubscriptionOrTrial', false)
        );
});

test('delete account page flags users with an active subscription', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_active_delete_page_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_delete_page_test',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('delete-account.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/delete-account')
            ->where('hasActiveSubscriptionOrTrial', true)
        );
});
