<?php

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Bank;
use App\Models\User;

test('users can create real estate accounts by default', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'My Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
});

test('users can still create non-real-estate accounts', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create();

    $response = $this->actingAs($user)->post(route('accounts.store'), [
        'name' => 'My Savings',
        'bank_id' => $bank->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Savings->value,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
});

test('real-estate flag is not shared with frontend anymore', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->missing('features.real-estate')
    );
});
