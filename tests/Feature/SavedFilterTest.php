<?php

use App\Models\SavedFilter;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('saved filters endpoints require authentication', function () {
    auth()->logout();

    $this->getJson('/api/saved-filters')->assertUnauthorized();
});

test('lists only the current user saved filters ordered by name', function () {
    SavedFilter::factory()->create(['user_id' => $this->user->id, 'name' => 'Zurich trip']);
    SavedFilter::factory()->create(['user_id' => $this->user->id, 'name' => 'Apartment bills']);
    SavedFilter::factory()->create(['name' => 'Someone else']);

    $response = $this->getJson('/api/saved-filters')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.name'))->toBe('Apartment bills');
    expect($response->json('data.1.name'))->toBe('Zurich trip');
});

test('stores a saved filter for the current user', function () {
    $payload = [
        'name' => 'Trip to Japan',
        'filters' => [
            'label_ids' => ['11111111-1111-1111-1111-111111111111'],
            'category_ids' => ['food'],
            'date_from' => '2026-01-01',
        ],
    ];

    $response = $this->postJson('/api/saved-filters', $payload)->assertCreated();

    expect($response->json('data.name'))->toBe('Trip to Japan');
    expect($response->json('data.filters.category_ids'))->toBe(['food']);

    $this->assertDatabaseHas('saved_filters', [
        'user_id' => $this->user->id,
        'name' => 'Trip to Japan',
    ]);
});

test('name is required and unique per user', function () {
    SavedFilter::factory()->create(['user_id' => $this->user->id, 'name' => 'Duplicate']);

    $this->postJson('/api/saved-filters', ['filters' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'filters']);

    $this->postJson('/api/saved-filters', ['name' => 'Duplicate', 'filters' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('the same name can be reused by a different user', function () {
    SavedFilter::factory()->create(['user_id' => $this->user->id, 'name' => 'Shared name']);

    $other = User::factory()->create();

    $this->actingAs($other)
        ->postJson('/api/saved-filters', [
            'name' => 'Shared name',
            'filters' => ['search' => 'groceries'],
        ])
        ->assertCreated();
});

test('a user can update their own saved filter', function () {
    $savedFilter = SavedFilter::factory()->create([
        'user_id' => $this->user->id,
        'filters' => ['search' => 'old'],
    ]);

    $response = $this->patchJson("/api/saved-filters/{$savedFilter->id}", [
        'filters' => ['search' => 'new', 'category_ids' => ['food']],
    ])->assertOk();

    expect($response->json('data.filters.search'))->toBe('new');

    $this->assertDatabaseHas('saved_filters', [
        'id' => $savedFilter->id,
        'name' => $savedFilter->name,
    ]);
    expect($savedFilter->fresh()->filters)->toBe([
        'search' => 'new',
        'category_ids' => ['food'],
    ]);
});

test('a user cannot update another user saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['filters' => ['search' => 'old']]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}", [
        'filters' => ['search' => 'hacked'],
    ])->assertForbidden();

    expect($savedFilter->fresh()->filters)->toBe(['search' => 'old']);
});

test('a user can delete their own saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['user_id' => $this->user->id]);

    $this->deleteJson("/api/saved-filters/{$savedFilter->id}")->assertOk();

    $this->assertDatabaseMissing('saved_filters', ['id' => $savedFilter->id]);
});

test('a user cannot delete another user saved filter', function () {
    $savedFilter = SavedFilter::factory()->create();

    $this->deleteJson("/api/saved-filters/{$savedFilter->id}")->assertForbidden();

    $this->assertDatabaseHas('saved_filters', ['id' => $savedFilter->id]);
});
