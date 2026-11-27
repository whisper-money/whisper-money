<?php

use App\Models\User;

beforeEach(function () {
    config(['app.admin_email' => 'admin@whisper.test']);
});

test('a signed-in user who is not the admin gets a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertNotFound();
});

test('every user is listed newest first, 50 to a page', function () {
    $admin = User::factory()->create(['email' => 'admin@whisper.test', 'created_at' => now()->subYear()]);
    $newest = User::factory()->create(['created_at' => now()->addMinute()]);
    User::factory()->count(54)->create();

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users')
            ->has('users.data', 50)
            ->where('users.data.0.id', $newest->id)
            ->where('users.total', 56)
        );

    $this->actingAs($admin)
        ->get('/admin/users?page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 6)
            ->where('users.data.5.id', $admin->id)
        );
});
