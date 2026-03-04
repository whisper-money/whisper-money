<?php

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\User;

it('returns pending false when user has no banking connections', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('returns pending false when all banking connections have been synced', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('returns pending true when an active connection has not been synced yet', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => true]);
});

it('returns pending false when unsynced connection has an error status', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->error()->create([
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('returns pending false when unsynced connection is revoked', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->revoked()->create([
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('requires authentication', function () {
    $this->getJson('/onboarding/sync-status')
        ->assertUnauthorized();
});

it('only considers the authenticated users connections', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $other = User::factory()->create(['onboarded_at' => null]);

    // Other user has a pending sync — should not affect our user
    BankingConnection::factory()->for($other)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});
