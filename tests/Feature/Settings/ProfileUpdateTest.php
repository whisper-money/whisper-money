<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'EUR',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
    expect($user->currency_code)->toBe('EUR');
});

test('profile accepts new latam primary currency', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'ARS',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    expect($user->refresh()->currency_code)->toBe('ARS');
});

test('profile accepts Pakistani rupee as primary currency', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'PKR',
            'month_start_day' => 1,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    expect($user->refresh()->currency_code)->toBe('PKR');
});

test('profile rejects bitcoin as primary currency', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'BTC',
        ]);

    $response->assertSessionHasErrors(['currency_code']);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
            'currency_code' => $user->currency_code,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->create();

    $originalEmail = $user->email;

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $deletedUser = User::withTrashed()->find($user->id);

    $this->assertGuest();
    expect(User::query()->find($user->id))->toBeNull();
    expect($deletedUser?->deleted_at)->not->toBeNull();
    expect($deletedUser?->email)->toBe('20260422105124_'.$originalEmail);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
