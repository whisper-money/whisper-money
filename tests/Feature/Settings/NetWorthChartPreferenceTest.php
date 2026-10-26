<?php

use App\Models\User;
use App\Models\UserSetting;

dataset('net worth chart preferences', [
    'loans' => ['net-worth-chart-loan-preference.update', 'include_loans_in_net_worth_chart', 'includeLoansInNetWorthChart'],
    'real estate' => ['net-worth-chart-real-estate-preference.update', 'include_real_estate_in_net_worth_chart', 'includeRealEstateInNetWorthChart'],
]);

test('the preference creates the setting when none exists', function (string $routeName, string $field) {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $response = $this
        ->actingAs($user)
        ->patch(route($routeName), [
            $field => false,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->setting->{$field})->toBeFalse();
})->with('net worth chart preferences');

test('the preference rejects invalid values', function (string $routeName, string $field) {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route($routeName), [
            $field => 'sometimes',
        ]);

    $response->assertSessionHasErrors($field);
})->with('net worth chart preferences');

test('the preference updates an existing setting', function (string $routeName, string $field) {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        $field => false,
    ]);

    $this->actingAs($user)
        ->patch(route($routeName), [
            $field => true,
        ]);

    expect($user->fresh()->setting->{$field})->toBeTrue();
})->with('net worth chart preferences');

test('the preference defaults to off when no setting exists', function (string $routeName, string $field, string $prop) {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where($prop, false));
})->with('net worth chart preferences');

test('the preference is shared via inertia', function (string $routeName, string $field, string $prop) {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        $field => false,
    ]);

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where($prop, false));
})->with('net worth chart preferences');
