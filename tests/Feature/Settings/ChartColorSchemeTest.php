<?php

use App\Enums\ChartColorScheme;
use App\Models\User;
use App\Models\UserSetting;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('chart color scheme can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('chart-color-scheme.update'), [
            'chart_color_scheme' => 'blue',
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->setting->chart_color_scheme)->toBe(ChartColorScheme::Blue);
});

test('chart color scheme rejects invalid values', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('chart-color-scheme.update'), [
            'chart_color_scheme' => 'rainbow',
        ]);

    $response->assertSessionHasErrors('chart_color_scheme');
});

test('chart color scheme requires authentication', function () {
    $response = $this->patch(route('chart-color-scheme.update'), [
        'chart_color_scheme' => 'blue',
    ]);

    $response->assertRedirect(route('register'));
});

test('chart color scheme creates setting when none exists', function () {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch(route('chart-color-scheme.update'), [
            'chart_color_scheme' => 'pink',
        ]);

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->setting->chart_color_scheme)->toBe(ChartColorScheme::Pink);
});

test('chart color scheme updates existing setting', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->withScheme(ChartColorScheme::Blue)->create();

    $this->actingAs($user)
        ->patch(route('chart-color-scheme.update'), [
            'chart_color_scheme' => 'neutral',
        ]);

    expect($user->fresh()->setting->chart_color_scheme)->toBe(ChartColorScheme::Neutral);
});

test('chart color scheme defaults to colorful when no setting exists', function () {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();
    expect($user->setting?->chart_color_scheme?->value ?? 'colorful')->toBe('colorful');
});

test('chart color scheme is shared via inertia', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->withScheme(ChartColorScheme::Pink)->create();

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('chartColorScheme', 'pink'));
});
