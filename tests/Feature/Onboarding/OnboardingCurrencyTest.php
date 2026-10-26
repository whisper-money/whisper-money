<?php

use App\Models\User;

it('sets the currency the user is quoted in', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'currency_code' => 'USD']);

    $this->actingAs($user)
        ->patch('/onboarding/currency', ['currency_code' => 'INR'])
        ->assertRedirect();

    expect($user->refresh()->currency_code)->toBe('INR');
});

// The guess is stored in the minor units of the currency it was made in, so
// read back in another one it would be a different amount altogether.
it('forgets a spending guess made in the previous currency', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'USD',
        'onboarding_answers' => ['goal' => 'debt', 'spending_guess' => 120000],
    ]);

    $this->actingAs($user)
        ->patch('/onboarding/currency', ['currency_code' => 'JPY'])
        ->assertRedirect();

    expect($user->refresh()->onboarding_answers)->toBe(['goal' => 'debt']);
});

it('keeps the guess when the currency picked is the one already set', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
        'onboarding_answers' => ['spending_guess' => 120000],
    ]);

    $this->actingAs($user)
        ->patch('/onboarding/currency', ['currency_code' => 'EUR'])
        ->assertRedirect();

    expect($user->refresh()->onboarding_answers)->toBe(['spending_guess' => 120000]);
});

it('rejects a currency that cannot be a primary one', function (string $currencyCode) {
    $user = User::factory()->create(['onboarded_at' => null, 'currency_code' => 'USD']);

    $this->actingAs($user)
        ->patch('/onboarding/currency', ['currency_code' => $currencyCode])
        ->assertSessionHasErrors('currency_code');

    expect($user->refresh()->currency_code)->toBe('USD');
})->with(['unknown' => 'XYZ', 'not a primary one' => 'BTC', 'lowercase' => 'eur', 'empty' => '']);
