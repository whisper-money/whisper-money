<?php

use App\Models\User;
use App\Models\UserSetting;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('notification preferences can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => ['bank_transactions_synced' => false],
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->setting->notify_on_bank_transactions_synced)->toBeFalse();
});

test('notification preferences reject unknown notification keys', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => ['unknown_notification' => true],
        ]);

    $response->assertSessionHasErrors('notifications');
});

test('notification preferences reject invalid values', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => ['bank_transactions_synced' => 'sometimes'],
        ]);

    $response->assertSessionHasErrors('notifications.bank_transactions_synced');
});

test('notification preferences require authentication', function () {
    $response = $this->patch(route('notifications.update'), [
        'notifications' => ['bank_transactions_synced' => false],
    ]);

    $response->assertRedirect(route('register'));
});

test('notification preferences create setting when none exists', function () {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => ['bank_transactions_synced' => false],
        ]);

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->setting->notify_on_bank_transactions_synced)->toBeFalse();
});

test('bank transactions notification defaults to true when no setting exists', function () {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();
    expect($user->wantsBankTransactionsSyncedEmail())->toBeTrue();
});

test('bank transactions notification preference is shared with the account page', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        'notify_on_bank_transactions_synced' => false,
    ]);

    $response = $this->actingAs($user)->get(route('account.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('notifyOnBankTransactionsSynced', false));
});
