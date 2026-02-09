<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('users can view their connections page', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    BankingConnection::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/connections')
        ->has('connections', 1)
    );
});

test('connections page only shows own connections', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    BankingConnection::factory()->create(['user_id' => $user->id]);
    BankingConnection::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('connections', 1)
    );
});

test('users can disconnect a banking connection', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')
        ->once();

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}");

    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->trashed())->toBeTrue();
});

test('users cannot disconnect another users connection', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}");

    $response->assertForbidden();
});

test('users can trigger manual sync on active connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('users cannot sync expired connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('error');
});
