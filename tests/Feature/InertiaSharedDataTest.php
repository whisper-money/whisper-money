<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

test('guests receive null auth user in shared props', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
    );
});

test('authenticated users receive auth user in shared props', function () {
    $user = User::factory()->create();

    $response = actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.email', $user->email)
    );
});

test('all pages receive app url in shared props', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('appUrl', config('app.url'))
    );
});

test('shared currency options split profile and account currencies', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('currencies.profile.0.code', 'USD')
        ->where('currencies.accounts.0.code', 'USD')
    );

    $props = $response->viewData('page')['props'];

    expect(collect($props['currencies']['profile'])->pluck('code'))->toContain('ARS');
    expect(collect($props['currencies']['profile'])->pluck('code'))->not->toContain('BTC');
    expect(collect($props['currencies']['accounts'])->pluck('code'))->toContain('BTC');
});
