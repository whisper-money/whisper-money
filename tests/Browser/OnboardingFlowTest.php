<?php

use App\Models\User;

it('redirects new registration to onboarding page', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->fill('name', 'Test Onboarding User')
        ->fill('email', 'onboarding-test@example.com')
        ->fill('password', 'password123456')
        ->fill('password_confirmation', 'password123456')
        ->click('@register-user-button')
        ->wait(3)
        ->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'onboarding-test@example.com',
        'name' => 'Test Onboarding User',
    ]);
});

it('redirects onboarded user away from onboarding page to dashboard', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});

it('redirects non-onboarded user from dashboard to onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();
});

it('shows welcome step as first onboarding step', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Welcome to Whisper Money')
        ->assertSee("Let's Get Started")
        ->assertNoJavascriptErrors();
});

it('navigates from welcome to encryption explanation', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->assertSee('Welcome to Whisper Money')
        ->click("Let's Get Started")
        ->wait(1)
        ->assertSee('Your Data, Your Privacy')
        ->assertSee('End-to-End Encryption')
        ->assertNoJavascriptErrors();
});

it('shows encryption setup after encryption explanation', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/onboarding');

    $page->click("Let's Get Started")
        ->wait(1)
        ->click('I Understand, Continue')
        ->wait(1)
        ->assertSee('Create Your Encryption Password')
        ->assertNoJavascriptErrors();
});

it('marks user as onboarded when completing onboarding', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'encryption_salt' => 'test-salt',
    ]);

    expect($user->isOnboarded())->toBeFalse();

    $this->actingAs($user)->post('/onboarding/complete');

    $user->refresh();
    expect($user->isOnboarded())->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});
