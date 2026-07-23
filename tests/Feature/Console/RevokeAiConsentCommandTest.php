<?php

use App\Models\User;

test('revokes active AI consent for a user by email', function () {
    $user = User::factory()->create(['email' => 'lucia@example.com']);
    $user->recordAiConsent();

    $this->artisan('ai:revoke-consent', ['email' => 'lucia@example.com'])
        ->expectsOutputToContain('Revoked AI consent')
        ->assertSuccessful();

    expect($user->fresh()->hasActiveAiConsent())->toBeFalse();
});

test('reports when the user has no active consent', function () {
    User::factory()->create(['email' => 'no-consent@example.com']);

    $this->artisan('ai:revoke-consent', ['email' => 'no-consent@example.com'])
        ->expectsOutputToContain('no active AI consent')
        ->assertSuccessful();
});

test('fails when the user does not exist', function () {
    $this->artisan('ai:revoke-consent', ['email' => 'ghost@example.com'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});
