<?php

use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns shared banks and user banks but not other users banks', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();

    $sharedBank = Bank::factory()->create(['user_id' => null]);
    $userBank = Bank::factory()->create(['user_id' => $this->user->id]);
    $otherUserBank = Bank::factory()->create(['user_id' => $otherUser->id]);

    $response = getJson('/api/sync/banks');

    $response->assertSuccessful();

    $bankIds = collect($response->json('data'))->pluck('id')->toArray();

    expect($bankIds)->toContain($sharedBank->id);
    expect($bankIds)->toContain($userBank->id);
    expect($bankIds)->not->toContain($otherUserBank->id);
});

it('returns only banks updated after specified timestamp', function () {
    actingAs($this->user);

    $oldBank = Bank::factory()->create([
        'user_id' => null,
        'updated_at' => now()->subDays(2),
    ]);

    $newBank = Bank::factory()->create([
        'user_id' => null,
        'updated_at' => now(),
    ]);

    $response = getJson('/api/sync/banks?since='.now()->subDay()->toIso8601String());

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $newBank->id);
});

it('requires authentication', function () {
    $response = getJson('/api/sync/banks');

    $response->assertUnauthorized();
});
