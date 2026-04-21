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

test('guests are redirected away from callback route', function () {
    $this->get('/open-banking/callback?code=test')
        ->assertRedirect(route('register'));
});

test('guests are redirected away from connections index', function () {
    $this->get('/settings/connections')
        ->assertRedirect(route('register'));
});
