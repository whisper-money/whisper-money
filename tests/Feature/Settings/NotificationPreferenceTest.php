<?php

use App\Models\User;
use App\Models\UserSetting;

beforeEach(function () {
    config()->set('inertia.ssr.enabled', false);
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

dataset('email toggles', [
    'bank transactions synced' => ['bank_transactions_synced', 'notify_on_bank_transactions_synced', 'wantsBankTransactionsSyncedEmail', 'notifyOnBankTransactionsSynced'],
    'inactive no bank reminder' => ['inactive_no_bank', 'notify_on_inactive_no_bank', 'wantsInactiveNoBankEmail', 'notifyOnInactiveNoBank'],
    'product news and offers' => ['marketing', 'notify_marketing', 'wantsMarketingEmails', 'notifyMarketing'],
]);

test('an email toggle can be turned off, creating the setting when none exists', function (string $key, string $column) {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => [$key => false],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->setting->{$column})->toBeFalse();
})->with('email toggles');

test('an email toggle defaults to true when no setting exists', function (string $key, string $column, string $wantsEmail) {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();
    expect($user->{$wantsEmail}())->toBeTrue();
})->with('email toggles');

test('an email toggle is shared with the notifications page', function (string $key, string $column, string $wantsEmail, string $prop) {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        $column => false,
    ]);

    $response = $this->actingAs($user)->get(route('notifications.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where($prop, false));
})->with('email toggles');

test('budget notification defaults can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('notifications.update'), [
            'notifications' => [
                'budget_new_transaction' => true,
                'budget_close_to_limit' => true,
                'budget_over_limit' => true,
            ],
        ])
        ->assertSessionHasNoErrors();

    $setting = $user->fresh()->setting;

    expect($setting->budget_notify_on_new_transaction)->toBeTrue()
        ->and($setting->budget_notify_on_close_to_limit)->toBeTrue()
        ->and($setting->budget_notify_on_over_limit)->toBeTrue();
});
