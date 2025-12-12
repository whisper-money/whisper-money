<?php

use App\Models\EncryptedMessage;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('authenticated user without encryption salt can access setup page', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->get(route('onboarding'));

    $response->assertSuccessful();
});

test('user can setup encryption', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->postJson('/api/encryption/setup', [
        'salt' => str_repeat('a', 24),
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
    ]);

    $response->assertSuccessful();

    $user->refresh();

    expect($user->encryption_salt)->toBe(str_repeat('a', 24));

    assertDatabaseHas('encrypted_messages', [
        'user_id' => $user->id,
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
    ]);
});

test('encryption setup requires valid salt', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->postJson('/api/encryption/setup', [
        'salt' => 'invalid',
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['salt']);
});

test('encryption setup requires valid iv', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->postJson('/api/encryption/setup', [
        'salt' => str_repeat('a', 24),
        'encrypted_content' => 'encrypted_test_content',
        'iv' => 'invalid',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['iv']);
});

test('user can retrieve encrypted message', function () {
    $user = User::factory()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    EncryptedMessage::query()->create([
        'user_id' => $user->id,
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
    ]);

    $response = actingAs($user)->getJson('/api/encryption/message');

    $response->assertSuccessful();
    $response->assertJson([
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
        'salt' => str_repeat('a', 24),
    ]);
});

test('user without encrypted message receives 404', function () {
    $user = User::factory()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    $response = actingAs($user)->getJson('/api/encryption/message');

    $response->assertNotFound();
});

test('user without onboarding is redirected to onboarding', function () {
    $user = User::factory()->notOnboarded()->create(['encryption_salt' => 'test-salt']);

    $response = actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('onboarding'));
});

test('onboarded user with encryption salt can access dashboard', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
});
