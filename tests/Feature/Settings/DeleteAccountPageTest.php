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
            ->where('mustCancelSubscription', false)
            ->where('cancelsUnpaidSubscription', false)
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
            ->where('mustCancelSubscription', true)
            ->where('cancelsUnpaidSubscription', false)
        );
});

test('delete account page lets a user with an unpaid subscription delete and says it gets cancelled', function (string $status) {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}_delete_page_test",
        'stripe_status' => $status,
        'stripe_price' => 'price_delete_page_test',
        'trial_ends_at' => now()->subDays(3),
    ]);

    $this
        ->actingAs($user)
        ->get(route('delete-account.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/delete-account')
            ->where('mustCancelSubscription', false)
            ->where('cancelsUnpaidSubscription', true)
        );
})->with(['past_due', 'unpaid', 'incomplete']);
