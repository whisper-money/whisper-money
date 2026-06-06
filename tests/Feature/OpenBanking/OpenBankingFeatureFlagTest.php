<?php

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('guests cannot access institutions route', function () {
    $this->getJson('/open-banking/institutions?country=ES')
        ->assertUnauthorized();
});

test('guests cannot access authorize route', function () {
    $this->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ])->assertUnauthorized();
});

test('guests hitting the callback with no resolvable connection are sent to login', function () {
    // The callback is intentionally public so iOS PWAs that return to Safari (without a
    // session) can still finalize the connection via the state token. With no state and
    // no session there is nothing to finalize, so the guest is sent to login.
    $this->get('/open-banking/callback?code=test')
        ->assertRedirect(route('login'));
});

test('guests are redirected away from connections index', function () {
    $this->get('/settings/connections')
        ->assertRedirect(route('register'));
});
