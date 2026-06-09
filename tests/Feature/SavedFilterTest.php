<?php

use App\Enums\AnalysisMode;
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

test('updates the analysis day override on a saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-days", ['analysis_days' => 21])
        ->assertOk()
        ->assertJsonPath('data.analysis_days', 21);

    expect($savedFilter->fresh()->analysis_days)->toBe(21);
});

test('clears the analysis day override when sent null', function () {
    $savedFilter = SavedFilter::factory()->create([
        'user_id' => $this->user->id,
        'analysis_days' => 14,
    ]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-days", ['analysis_days' => null])
        ->assertOk()
        ->assertJsonPath('data.analysis_days', null);

    expect($savedFilter->fresh()->analysis_days)->toBeNull();
});

test('cannot update the analysis days of another user saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['name' => 'Not mine']);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-days", ['analysis_days' => 5])
        ->assertForbidden();
});

test('rejects an invalid analysis day override', function () {
    $savedFilter = SavedFilter::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-days", ['analysis_days' => 0])
        ->assertJsonValidationErrors('analysis_days');
});

test('updates the analysis view mode on a saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-mode", ['analysis_mode' => 'income'])
        ->assertOk()
        ->assertJsonPath('data.analysis_mode', 'income');

    expect($savedFilter->fresh()->analysis_mode)->toBe(AnalysisMode::Income);
});

test('clears the analysis view mode when sent null', function () {
    $savedFilter = SavedFilter::factory()->create([
        'user_id' => $this->user->id,
        'analysis_mode' => 'expense',
    ]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-mode", ['analysis_mode' => null])
        ->assertOk()
        ->assertJsonPath('data.analysis_mode', null);

    expect($savedFilter->fresh()->analysis_mode)->toBeNull();
});

test('cannot update the analysis mode of another user saved filter', function () {
    $savedFilter = SavedFilter::factory()->create(['name' => 'Not mine']);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-mode", ['analysis_mode' => 'income'])
        ->assertForbidden();
});

test('rejects an unknown analysis view mode', function () {
    $savedFilter = SavedFilter::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/saved-filters/{$savedFilter->id}/analysis-mode", ['analysis_mode' => 'sideways'])
        ->assertJsonValidationErrors('analysis_mode');
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
