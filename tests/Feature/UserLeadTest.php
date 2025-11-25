<?php

use App\Models\UserLead;
use Illuminate\Support\Facades\Config;

test('user lead is created successfully', function () {
    $email = 'test@example.com';

    $response = $this->post(route('user-leads.store'), [
        'email' => $email,
    ]);

    $this->assertDatabaseHas('user_leads', [
        'email' => $email,
    ]);

    expect(UserLead::where('email', $email)->exists())->toBeTrue();
});

test('user lead redirects to home when no lead redirect url is configured', function () {
    Config::set('landing.lead_redirect_url', null);

    $email = 'test@example.com';

    $response = $this->post(route('user-leads.store'), [
        'email' => $email,
    ]);

    $response->assertRedirect(route('home'));
});

test('user lead redirects to lead redirect url with email parameter', function () {
    $redirectUrl = 'https://example.com/form';
    Config::set('landing.lead_redirect_url', $redirectUrl);

    $email = 'test@example.com';

    $response = $this->post(route('user-leads.store'), [
        'email' => $email,
    ]);

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', $redirectUrl.'?email='.urlencode($email));
});

test('user lead redirects to lead redirect url with special characters in email', function () {
    $redirectUrl = 'https://example.com/form';
    Config::set('landing.lead_redirect_url', $redirectUrl);

    $email = 'test+special@example.com';

    $response = $this->post(route('user-leads.store'), [
        'email' => $email,
    ]);

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', $redirectUrl.'?email='.urlencode($email));
});

test('user lead cannot be created with duplicate email', function () {
    $email = 'test@example.com';

    UserLead::factory()->create(['email' => $email]);

    $response = $this->post(route('user-leads.store'), [
        'email' => $email,
    ]);

    $response->assertSessionHasErrors('email');
});

test('user lead requires valid email', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'invalid-email',
    ]);

    $response->assertSessionHasErrors('email');
});
