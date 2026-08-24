<?php

use App\Models\AiConsent;
use App\Models\User;

test('deletes all AI consent records for a user by email', function () {
    $user = User::factory()->create(['email' => 'lucia@example.com']);
    $user->recordAiConsent();
    AiConsent::factory()->revoked()->for($user)->create();

    $this->artisan('ai:delete-consent', ['email' => 'lucia@example.com'])
        ->expectsOutputToContain('Deleted AI consent')
        ->assertSuccessful();

    expect($user->aiConsents()->count())->toBe(0);
});

test('reports when the user has no consent on record', function () {
    User::factory()->create(['email' => 'no-consent@example.com']);

    $this->artisan('ai:delete-consent', ['email' => 'no-consent@example.com'])
        ->expectsOutputToContain('no AI consent on record')
        ->assertSuccessful();
});

test('fails when the user does not exist', function () {
    $this->artisan('ai:delete-consent', ['email' => 'ghost@example.com'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});
