<?php

use App\Models\User;
use App\Models\UserSetting;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('net worth chart loan preference can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('net-worth-chart-loan-preference.update'), [
            'include_loans_in_net_worth_chart' => false,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->setting->include_loans_in_net_worth_chart)->toBeFalse();
});

test('net worth chart loan preference rejects invalid values', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('net-worth-chart-loan-preference.update'), [
            'include_loans_in_net_worth_chart' => 'sometimes',
        ]);

    $response->assertSessionHasErrors('include_loans_in_net_worth_chart');
});

test('net worth chart loan preference requires authentication', function () {
    $response = $this->patch(route('net-worth-chart-loan-preference.update'), [
        'include_loans_in_net_worth_chart' => false,
    ]);

    $response->assertRedirect(route('register'));
});

test('net worth chart loan preference creates setting when none exists', function () {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch(route('net-worth-chart-loan-preference.update'), [
            'include_loans_in_net_worth_chart' => false,
        ]);

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->setting->include_loans_in_net_worth_chart)->toBeFalse();
});

test('net worth chart loan preference updates existing setting', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        'include_loans_in_net_worth_chart' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('net-worth-chart-loan-preference.update'), [
            'include_loans_in_net_worth_chart' => true,
        ]);

    expect($user->fresh()->setting->include_loans_in_net_worth_chart)->toBeTrue();
});

test('net worth chart loan preference defaults to true when no setting exists', function () {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();
    expect($user->setting?->include_loans_in_net_worth_chart ?? true)->toBeTrue();
});

test('net worth chart loan preference is shared via inertia', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        'include_loans_in_net_worth_chart' => false,
    ]);

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('includeLoansInNetWorthChart', false));
});
