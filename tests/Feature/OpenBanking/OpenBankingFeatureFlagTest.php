<?php

use App\Models\User;
use Laravel\Pennant\Feature;

test('users without open-banking feature get 404 on institutions', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->get('/open-banking/institutions?country=ES');

    $response->assertNotFound();
});

test('users without open-banking feature get 404 on authorize', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->post('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertNotFound();
});

test('users without open-banking feature get 404 on callback', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test');

    $response->assertNotFound();
});

test('users without open-banking feature get 404 on connections index', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertNotFound();
});

test('open-banking feature flag is shared with frontend when enabled', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('open-banking');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.open-banking', true)
    );
});

test('open-banking feature flag is shared with frontend when disabled', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.open-banking', false)
    );
});

test('guests see open-banking feature as false', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.open-banking', false)
    );
});

test('open-banking feature flag is shared with frontend on onboarding page when enabled', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    Feature::for($user)->activate('open-banking');

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.open-banking', true)
    );
});

test('open-banking feature flag is shared with frontend on onboarding page when disabled', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    Feature::for($user)->deactivate('open-banking');

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.open-banking', false)
    );
});
