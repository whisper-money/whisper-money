<?php

use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

it('lists every space the user can access, personal first', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $shared = Space::factory()->create(['owner_id' => $owner->id, 'name' => 'Household', 'personal' => false]);
    $shared->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Space::factory()->create(['owner_id' => $owner->id, 'name' => 'Not mine', 'personal' => false]);

    withHeaders(apiV1Bearer($user))
        ->getJson('/api/v1/spaces')
        ->assertOk()
        ->assertExactJson(['data' => [
            [
                'id' => $user->personalSpace->id,
                'name' => $user->personalSpace->name,
                'personal' => true,
                'owned' => true,
                'created_at' => $user->personalSpace->created_at->toIso8601String(),
            ],
            [
                'id' => $shared->id,
                'name' => 'Household',
                'personal' => false,
                'owned' => false,
                'created_at' => $shared->created_at->toIso8601String(),
            ],
        ]]);
});
