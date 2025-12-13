<?php

use App\Models\Label;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns all labels for authenticated user', function () {
    actingAs($this->user);

    Label::factory()
        ->count(3)
        ->create(['user_id' => $this->user->id]);

    Label::factory()
        ->count(2)
        ->create();

    $response = getJson('/api/sync/labels');

    $response->assertSuccessful();
    $response->assertJsonCount(3, 'data');
});

it('returns only labels updated after specified timestamp', function () {
    actingAs($this->user);

    $oldLabel = Label::factory()->create([
        'user_id' => $this->user->id,
        'updated_at' => now()->subDays(2),
    ]);

    $newLabel = Label::factory()->create([
        'user_id' => $this->user->id,
        'updated_at' => now(),
    ]);

    $response = getJson('/api/sync/labels?since='.now()->subDay()->toIso8601String());

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $newLabel->id);
});

it('requires authentication', function () {
    $response = getJson('/api/sync/labels');

    $response->assertUnauthorized();
});
