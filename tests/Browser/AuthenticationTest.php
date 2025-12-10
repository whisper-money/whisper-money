<?php

use App\Models\User;

it('can register a new user', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->fill('name', 'Test User')
        ->fill('email', 'newuser@example.com')
        ->fill('password', 'password123')
        ->fill('password_confirmation', 'password123')
        ->click('@register-user-button')
        ->wait(2)
        ->assertSee('Welcome to Whisper Money')
        ->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'newuser@example.com',
        'name' => 'Test User',
    ]);
});

it('shows validation errors for invalid registration', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->fill('name', 'Test User')
        ->fill('email', 'invalid-email')
        ->fill('password', 'short')
        ->fill('password_confirmation', 'different')
        ->click('@register-user-button')
        ->wait(1)
        ->assertNoJavascriptErrors();
});

it('can login with valid credentials', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $page = visit('/login');

    $page->assertSee('Log in to your account')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password123')
        ->click('@login-button')
        ->wait(2)
        ->assertSee('Dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    $this->assertAuthenticated();
});

it('shows validation error for invalid login credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $page = visit('/login');

    $page->assertSee('Log in to your account')
        ->fill('email', 'test@example.com')
        ->fill('password', 'wrongpassword')
        ->click('@login-button')
        ->wait(1)
        ->assertSee('These credentials do not match our records')
        ->assertNoJavascriptErrors();
});

it('can navigate from login to register', function () {
    $page = visit('/login');

    $page->assertSee('Log in to your account')
        ->click('Sign up')
        ->wait(1)
        ->assertSee('Create an account')
        ->assertPathIs('/register')
        ->assertNoJavascriptErrors();
});

it('can navigate from register to login', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->click('Log in')
        ->wait(1)
        ->assertSee('Log in to your account')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();
});
