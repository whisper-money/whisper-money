<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

test('guests receive null auth user in shared props', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
    );
});

test('authenticated users receive auth user in shared props', function () {
    $user = User::factory()->create();

    $response = actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.email', $user->email)
    );
});
