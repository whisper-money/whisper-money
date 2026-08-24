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

it('reports a rate limited connection as failed instead of pending', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->rateLimited()->create();

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => true]);
});

it('keeps waiting while a healthy connection syncs alongside a failed one', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->rateLimited()->create();
    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => true, 'failed' => false]);
});

it('does not report a synced connection as failed', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => false]);
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
