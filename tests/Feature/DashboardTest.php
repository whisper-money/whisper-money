<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]));

    $this->get(route('dashboard'))->assertOk();
});
