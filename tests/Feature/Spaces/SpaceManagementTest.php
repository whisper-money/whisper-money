<?php

use App\Actions\CreateDefaultCategories;
use App\Features\Spaces;
use App\Models\Account;
use App\Models\Category;
use App\Models\Space;
use App\Models\User;
use Laravel\Pennant\Feature;

it('creates a space, seeds its categories and switches to it', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Spaces::class);

    // The personal space already carries the default categories (as it would
    // after registration), so seeding the same names into a second space must
    // NOT collide — category name uniqueness is per space, not per user.
    app(CreateDefaultCategories::class)->handle($user, $user->personalSpace);

    $this->actingAs($user)->post('/settings/spaces', ['name' => 'Acme'])
        ->assertRedirect();

    $space = Space::query()->where('owner_id', $user->id)->where('personal', false)->first();

    expect($space)->not->toBeNull()
        ->and($user->fresh()->current_space_id)->toBe($space->id)
        ->and(Category::where('space_id', $space->id)->count())->toBeGreaterThan(0)
        ->and(Category::where('space_id', $user->personalSpace->id)->count())->toBeGreaterThan(0);
});

it('forbids creating a space without the spaces feature', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post('/settings/spaces', ['name' => 'Acme'])
        ->assertForbidden();
});

it('lets the owner rename a non-personal space', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Spaces::class);
    $space = $user->ownedSpaces()->create(['name' => 'Old', 'personal' => false]);

    $this->actingAs($user)->patch("/settings/spaces/{$space->id}", ['name' => 'New'])
        ->assertRedirect();

    expect($space->fresh()->name)->toBe('New');
});

it('never lets the personal space be renamed or deleted', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Spaces::class);
    $personal = $user->personalSpace;

    $this->actingAs($user)->patch("/settings/spaces/{$personal->id}", ['name' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($user)->delete("/settings/spaces/{$personal->id}")
        ->assertForbidden();
});

it('refuses to delete a space that still has accounts', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Spaces::class);
    $space = $user->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);
    Account::factory()->for($user)->create(['space_id' => $space->id]);

    $this->actingAs($user)->delete("/settings/spaces/{$space->id}")
        ->assertSessionHas('error');

    expect($space->fresh())->not->toBeNull();
});

it('deletes an empty space and falls back to the personal space', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Spaces::class);
    $space = $user->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);
    $user->forceFill(['current_space_id' => $space->id])->save();

    $this->actingAs($user)->delete("/settings/spaces/{$space->id}")
        ->assertRedirect();

    expect(Space::find($space->id))->toBeNull()
        ->and($user->fresh()->current_space_id)->toBe($user->personalSpace->id);
});

it('switches the active space and rejects spaces the user cannot access', function () {
    $user = User::factory()->onboarded()->create();
    $space = $user->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);

    $this->actingAs($user)->post("/spaces/{$space->id}/switch")
        ->assertRedirect();
    expect($user->fresh()->current_space_id)->toBe($space->id);

    $stranger = User::factory()->create();
    $strangerSpace = $stranger->personalSpace;

    $this->actingAs($user)->post("/spaces/{$strangerSpace->id}/switch")
        ->assertForbidden();
});
