<?php

use App\Models\User;

beforeEach(function () {
    config(['app.demo' => [
        'email' => 'demo@whisper.money',
        'password' => 'demo',
        'encryption_key' => 'demo',
    ]]);

    $this->artisan('demo:reset')->assertSuccessful();
    $this->demoUser = User::where('email', 'demo@whisper.money')->first();
});

test('demo account cannot change password', function () {
    $this->actingAs($this->demoUser);

    $this->put(route('user-password.update'), [
        'current_password' => 'demo',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertSessionHasErrors('demo');
});

test('demo account cannot delete account', function () {
    $this->actingAs($this->demoUser);

    $this->delete(route('profile.destroy'), [
        'password' => 'demo',
    ])->assertSessionHasErrors('demo');

    expect(User::where('email', 'demo@whisper.money')->exists())->toBeTrue();
});

test('demo account cannot enable two-factor authentication', function () {
    $this->actingAs($this->demoUser);

    $this->post('/user/two-factor-authentication')
        ->assertRedirect()
        ->assertSessionHasErrors('demo');
});

test('demo account cannot access billing portal', function () {
    config(['subscriptions.enabled' => true]);

    $this->actingAs($this->demoUser);

    $this->get(route('settings.billing.portal'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasErrors('demo');
});

test('regular user can change password', function () {
    $user = User::factory()->create([
        'password' => 'oldpassword123',
    ]);

    $this->actingAs($user);

    $this->put(route('user-password.update'), [
        'current_password' => 'oldpassword123',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertSessionHasNoErrors();
});

test('regular user can delete account', function () {
    $user = User::factory()->create([
        'password' => 'password123',
    ]);

    $this->actingAs($user);

    $this->delete(route('profile.destroy'), [
        'password' => 'password123',
    ])->assertRedirect('/');

    expect(User::where('id', $user->id)->exists())->toBeFalse();
});

test('isDemoAccount returns true for demo user', function () {
    expect($this->demoUser->isDemoAccount())->toBeTrue();
});

test('isDemoAccount returns false for regular user', function () {
    $user = User::factory()->create();
    expect($user->isDemoAccount())->toBeFalse();
});
