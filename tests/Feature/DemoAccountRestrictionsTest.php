<?php

use App\Models\User;

test('demo account has restricted actions', function () {
    config(['app.demo.email' => 'demo@whisper.money']);
    config(['subscriptions.enabled' => true]);

    $demoUser = User::factory()->create([
        'email' => 'demo@whisper.money',
        'password' => 'demo',
    ]);

    $this->actingAs($demoUser);

    // Cannot change password
    $this->put(route('user-password.update'), [
        'current_password' => 'demo',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertSessionHasErrors('demo');

    // Cannot delete account
    $this->delete(route('profile.destroy'), [
        'password' => 'demo',
    ])->assertSessionHasErrors('demo');

    // Cannot enable 2FA
    $this->post('/user/two-factor-authentication')
        ->assertSessionHasErrors('demo');

    // Cannot access billing portal
    $this->get(route('settings.billing.portal'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasErrors('demo');

    expect($demoUser->isDemoAccount())->toBeTrue();
});

test('regular user is not restricted', function () {
    $user = User::factory()->create([
        'password' => 'password123',
    ]);

    $this->actingAs($user);

    $this->put(route('user-password.update'), [
        'current_password' => 'password123',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertSessionHasNoErrors();

    expect($user->isDemoAccount())->toBeFalse();
});
