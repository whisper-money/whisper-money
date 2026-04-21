<?php

use App\Models\User;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('timezone can be backfilled for authenticated users without one', function () {
    $user = User::factory()->create(['timezone' => null]);

    $response = $this->actingAs($user)->patchJson(route('timezone.update'), [
        'timezone' => 'Europe/Madrid',
    ]);

    $response->assertNoContent();

    expect($user->refresh()->timezone)->toBe('Europe/Madrid');
});

test('timezone backfill does not overwrite an existing timezone', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $response = $this->actingAs($user)->patchJson(route('timezone.update'), [
        'timezone' => 'Europe/Madrid',
    ]);

    $response->assertNoContent();

    expect($user->refresh()->timezone)->toBe('America/New_York');
});

test('timezone backfill rejects invalid timezones', function () {
    $user = User::factory()->create(['timezone' => null]);

    $response = $this->actingAs($user)->patchJson(route('timezone.update'), [
        'timezone' => 'Mars/Olympus_Mons',
    ]);

    $response->assertJsonValidationErrors(['timezone']);
    expect($user->refresh()->timezone)->toBeNull();
});

test('timezone backfill requires authentication', function () {
    $response = $this->patch(route('timezone.update'), [
        'timezone' => 'Europe/Madrid',
    ]);

    $response->assertRedirect(route('register'));
});
