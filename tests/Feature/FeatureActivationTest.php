<?php

use App\Models\User;
use Laravel\Pennant\Feature;

test('activating a valid feature for an existing user redirects to dashboard', function () {
    $user = User::factory()->create();

    $response = $this->get(route('features.activate', [
        'feature' => 'budgets',
        'email' => $user->email,
    ]));

    $response->assertRedirect(route('dashboard'));

    expect(Feature::for($user)->active('budgets'))->toBeTrue();
});

test('activating a feature for a non-existent user still redirects to dashboard', function () {
    $response = $this->get(route('features.activate', [
        'feature' => 'budgets',
        'email' => 'nonexistent@example.com',
    ]));

    $response->assertRedirect(route('dashboard'));
});

test('activating a non-existent feature returns 404', function () {
    $response = $this->get(route('features.activate', [
        'feature' => 'nonexistent',
        'email' => 'test@example.com',
    ]));

    $response->assertNotFound();
});

test('missing email query param returns validation error', function () {
    $response = $this->get(route('features.activate', [
        'feature' => 'budgets',
    ]));

    $response->assertSessionHasErrors('email');
});

test('invalid email query param returns validation error', function () {
    $response = $this->get(route('features.activate', [
        'feature' => 'budgets',
        'email' => 'not-an-email',
    ]));

    $response->assertSessionHasErrors('email');
});
