<?php

it('can register a new user', function () {
    $page = visit('/register?force=1');

    $page->assertSee('Create an account')
        ->fill('name', 'Test User')
        ->fill('email', 'newuser@example.com')
        ->fill('password', 'password123')
        ->fill('password_confirmation', 'password123')
        ->click('@register-user-button')
        ->assertPathIs('/email/verify')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'newuser@example.com',
        'name' => 'Test User',
    ]);
});
