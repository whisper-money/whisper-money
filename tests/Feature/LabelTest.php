<?php

use App\Models\Label;
use App\Models\User;

test('authenticated users can create a label', function () {
    $user = User::factory()->create();

    $labelData = [
        'name' => 'Important',
        'color' => 'blue',
    ];

    $response = $this->actingAs($user)->post(route('labels.store'), $labelData);

    $response->assertRedirect(route('labels.index'));

    $this->assertDatabaseHas('labels', [
        'user_id' => $user->id,
        'name' => 'Important',
        'color' => 'blue',
    ]);
});

test('label name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('labels.store'), [
        'color' => 'blue',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
});

test('label color is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('labels.store'), [
        'name' => 'Important',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['color']);
});

test('label color must be valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('labels.store'), [
        'name' => 'Important',
        'color' => 'invalid-color',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['color']);
});

test('label names are unique per user', function () {
    $user = User::factory()->create();

    Label::factory()->create([
        'user_id' => $user->id,
        'name' => 'Urgent',
    ]);

    $response = $this->actingAs($user)->postJson(route('labels.store'), [
        'name' => 'Urgent',
        'color' => 'red',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
});

test('different users can have labels with the same name', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Label::factory()->create([
        'user_id' => $user1->id,
        'name' => 'Important',
    ]);

    $response = $this->actingAs($user2)->post(route('labels.store'), [
        'name' => 'Important',
        'color' => 'blue',
    ]);

    $response->assertRedirect(route('labels.index'));

    $this->assertDatabaseHas('labels', [
        'user_id' => $user2->id,
        'name' => 'Important',
        'color' => 'blue',
    ]);
});

test('authenticated users can update their own label', function () {
    $user = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $user->id]);

    $updateData = [
        'name' => 'Updated Name',
        'color' => 'green',
    ];

    $response = $this->actingAs($user)->patch(
        route('labels.update', $label),
        $updateData
    );

    $response->assertRedirect(route('labels.index'));

    $this->assertDatabaseHas('labels', [
        'id' => $label->id,
        'name' => 'Updated Name',
        'color' => 'green',
    ]);
});

test('users cannot update labels they do not own', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->patch(
        route('labels.update', $label),
        [
            'name' => 'Updated Name',
            'color' => 'green',
        ]
    );

    $response->assertForbidden();
});

test('authenticated users can delete their own label', function () {
    $user = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('labels.destroy', $label));

    $response->assertRedirect(route('labels.index'));

    $this->assertSoftDeleted('labels', [
        'id' => $label->id,
    ]);
});

test('users cannot delete labels they do not own', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete(route('labels.destroy', $label));

    $response->assertForbidden();

    $this->assertDatabaseHas('labels', [
        'id' => $label->id,
        'deleted_at' => null,
    ]);
});

test('guests cannot access label management', function () {
    $response = $this->postJson(route('labels.store'), []);
    $response->assertUnauthorized();
});
