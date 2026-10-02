<?php

use App\Models\User;
use Laravel\Fortify\Features;
use Laravel\Passport\ClientRepository;

/**
 * Connecting ChatGPT or Claude sends the user through /oauth/authorize, which
 * ends in a redirect to the client's own domain once it is granted.
 */
function oauthAuthorizeUrl(): string
{
    return route('passport.authorizations.authorize', ['client_id' => 'chatgpt', 'state' => 'abc']);
}

test('an OAuth authorization lands guests on the login form, even on a first visit', function () {
    $callback = 'https://chatgpt.com/connector/oauth/callback';
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'ChatGPT',
        redirectUris: [$callback],
        confidential: false,
    );

    $this->get(route('passport.authorizations.authorize', [
        'client_id' => $client->id,
        'redirect_uri' => $callback,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'abc',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]))->assertRedirect(route('login'));
});

test('other pages still send a first visit to the sign-up form', function () {
    $this->get(route('dashboard'))->assertRedirect(route('register'));
});

test('logging in to finish an OAuth authorization leaves Inertia with a full page visit', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    // A plain redirect would be followed by the login form's XHR, and the
    // browser blocks the cross-origin hop to the client that comes after it.
    $this->withSession(['url.intended' => oauthAuthorizeUrl()])
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', oauthAuthorizeUrl());

    $this->assertAuthenticatedAs($user);
});

test('logging in to finish an OAuth authorization without Inertia is a plain redirect', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->withSession(['url.intended' => oauthAuthorizeUrl()])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(oauthAuthorizeUrl());
});

test('logging in through Inertia still redirects to an app page as usual', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('passing the two-factor challenge to finish an OAuth authorization leaves Inertia with a full page visit', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->withSession(['url.intended' => oauthAuthorizeUrl()])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', oauthAuthorizeUrl());
});
