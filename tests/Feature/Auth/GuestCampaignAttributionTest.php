<?php

use App\Models\User;
use Illuminate\Support\Arr;

$campaign = [
    'utm_source' => 'drip',
    'utm_medium' => 'email',
    'utm_campaign' => 'trial_ending_canceled',
    'utm_content' => 'cta',
];

test('a guest following an emailed link reaches the login page with its campaign parameters', function () use ($campaign) {
    $this
        ->get(route('settings.billing', $campaign))
        ->assertRedirect(route('login', $campaign));
});

test('a new guest reaches the sign-up page with the campaign parameters of the link', function () use ($campaign) {
    $this
        ->get(route('dashboard', $campaign))
        ->assertRedirect(route('register', $campaign));
});

test('only campaign parameters follow a guest to the login page', function () {
    $this
        ->withCookie('whisper_money_returning_user', '1')
        ->get(route('transactions.index', [
            'utm_campaign' => 'inactive_banked',
            'utm_term' => ['nested'],
            'utm_medium' => '',
            'account' => 'abc',
        ]))
        ->assertRedirect(route('login', ['utm_campaign' => 'inactive_banked']));
});

test('a guest without campaign parameters reaches the bare login page', function () {
    $this
        ->get(route('settings.billing'))
        ->assertRedirect(route('login'));
});

test('signing in sends the user on to the emailed link, campaign parameters included', function () use ($campaign) {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->get(route('settings.billing', $campaign));

    $this
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('settings.billing', Arr::sortRecursive($campaign)));
});
