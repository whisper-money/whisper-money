<?php

use App\Models\EncryptedMessage;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('authenticated user without encryption salt can access setup page', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->get(route('setup-encryption'));

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

test('user can update encrypted message', function () {
    $user = User::factory()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    $message = EncryptedMessage::query()->create([
        'user_id' => $user->id,
        'encrypted_content' => 'old_encrypted_content',
        'iv' => str_repeat('b', 16),
    ]);

    $response = actingAs($user)->putJson('/api/encryption/message', [
        'encrypted_content' => 'new_encrypted_content',
        'iv' => str_repeat('c', 16),
    ]);

    $response->assertSuccessful();

    $message->refresh();

    expect($message->encrypted_content)->toBe('new_encrypted_content');
    expect($message->iv)->toBe(str_repeat('c', 16));
});

test('user without encryption salt is redirected to setup', function () {
    $user = User::factory()->create(['encryption_salt' => null]);

    $response = actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('setup-encryption'));
});

test('user with encryption salt can access dashboard', function () {
    $user = User::factory()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    $response = actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
});
