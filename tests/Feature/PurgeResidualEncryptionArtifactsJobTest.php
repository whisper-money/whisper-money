<?php

use App\Jobs\PurgeResidualEncryptionArtifactsJob;
use App\Models\Account;
use App\Models\EncryptedMessage;
use App\Models\Transaction;
use App\Models\User;

function userWithEncryptionArtifacts(): User
{
    $user = User::factory()->onboarded()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    EncryptedMessage::query()->create([
        'user_id' => $user->id,
        'encrypted_content' => 'encrypted_test_content',
        'iv' => str_repeat('b', 16),
    ]);

    return $user;
}

test('it clears the salt and encrypted message when no encrypted data remains', function () {
    $user = userWithEncryptionArtifacts();

    PurgeResidualEncryptionArtifactsJob::dispatchSync($user);

    expect($user->fresh()->encryption_salt)->toBeNull();
    expect(EncryptedMessage::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('it keeps the salt when an encrypted transaction still exists', function () {
    $user = userWithEncryptionArtifacts();
    Transaction::factory()->create([
        'user_id' => $user->id,
        'description_iv' => str_repeat('c', 16),
    ]);

    PurgeResidualEncryptionArtifactsJob::dispatchSync($user);

    expect($user->fresh()->encryption_salt)->toBe(str_repeat('a', 24));
    expect(EncryptedMessage::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('it keeps the salt when an encrypted account still exists', function () {
    $user = userWithEncryptionArtifacts();
    Account::factory()->create([
        'user_id' => $user->id,
        'encrypted' => true,
    ]);

    PurgeResidualEncryptionArtifactsJob::dispatchSync($user);

    expect($user->fresh()->encryption_salt)->toBe(str_repeat('a', 24));
    expect(EncryptedMessage::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('it is a no-op when the salt is already null', function () {
    $user = User::factory()->onboarded()->create(['encryption_salt' => null]);

    PurgeResidualEncryptionArtifactsJob::dispatchSync($user);

    expect($user->fresh()->encryption_salt)->toBeNull();
});
