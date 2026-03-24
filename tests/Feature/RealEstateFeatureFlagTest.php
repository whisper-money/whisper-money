<?php

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\User;
use Laravel\Pennant\Feature;

test('users without real-estate feature cannot create real estate accounts', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('real-estate');

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'My Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
    ]);

    $response->assertForbidden();
});

test('users with real-estate feature can create real estate accounts', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('real-estate');

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'My Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
});

test('users without real-estate feature can still create non-real-estate accounts', function () {
    $user = User::factory()->onboarded()->create();
    $bank = \App\Models\Bank::factory()->create();

    Feature::for($user)->deactivate('real-estate');

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'My Savings',
        'bank_id' => $bank->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Savings->value,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
});

test('real-estate feature flag is shared with frontend when enabled', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('real-estate');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.real-estate', true)
    );
});

test('real-estate feature flag is shared with frontend when disabled', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->deactivate('real-estate');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.real-estate', false)
    );
});

test('guests see real-estate feature as false', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('features.real-estate', false)
    );
});
