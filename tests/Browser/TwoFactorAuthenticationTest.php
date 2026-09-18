<?php

declare(strict_types=1);

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AuthBrowserFlow;

/**
 * Two-factor authentication end to end, driven with real codes: the secret the
 * settings screen hands out is read back from the database and turned into a
 * TOTP with the same engine Fortify verifies against, so nothing here is faked.
 *
 * The three tests are the three ways an account can be locked out by a break in
 * this area — the setup never completing, the challenge never accepting a code,
 * and the switch never turning back off.
 */
beforeEach(function () {
    // Two-factor is not a paid feature, and the gate would otherwise decide
    // where a signed-in user lands.
    config(['subscriptions.enabled' => false]);
});

/**
 * A user whose two-factor is already confirmed, with recovery codes we can name
 * in an assertion. Fortify only ever writes these itself, so the shape matters:
 * a JSON list, encrypted, alongside the secret the challenge verifies against.
 *
 * @return array{0: User, 1: string}
 */
function userWithConfirmedTwoFactorForBrowser(): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = AuthBrowserFlow::user();

    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode([
            'aaaaaaaaaa-bbbbbbbbbb',
            'cccccccccc-dddddddddd',
        ])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$user, $secret];
}

/**
 * The recovery codes as the database holds them.
 *
 * @return list<string>
 */
function twoFactorRecoveryCodesOf(User $user): array
{
    return json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true);
}

it('enables two-factor from settings with a code from the secret it shows', function () {
    $user = AuthBrowserFlow::user();

    AuthBrowserFlow::signInAndOpenDashboard($user);

    // The settings screen sits behind a password confirmation, so turning
    // two-factor on costs a borrowed session more than one click.
    $page = visit('/settings/two-factor');

    $page->assertSee('Confirm your password')
        ->fill('password', AuthBrowserFlow::PASSWORD)
        ->click('@confirm-password-button')
        ->assertPathIs('/settings/two-factor')
        ->assertSee('Manage your two-factor authentication settings')
        ->assertSee('Disabled')
        ->click('Enable 2FA')
        ->assertSee('Enable Two-Factor Authentication')
        ->assertSee('or, enter the code manually');

    $secret = decrypt($user->refresh()->two_factor_secret);

    // The key on screen is the one that was stored, which is the whole promise
    // of the QR code beside it.
    $page->assertValue('input[readonly]', $secret);

    $page->click('Continue')
        ->assertSee('Verify Authentication Code')
        ->fill('otp', app(Google2FA::class)->getCurrentOtp($secret))
        ->click('Confirm')
        ->assertSee('Enabled')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $codesBefore = twoFactorRecoveryCodesOf($user);

    // Recovery codes are the way back in when the phone is gone, so they have to
    // be readable and replaceable from this same screen.
    $page->click('[aria-controls="recovery-codes-section"]')
        ->assertSee('Each recovery code can be used once')
        ->assertCount('#recovery-codes-section [role="listitem"]', 8)
        ->assertSee($codesBefore[0]);

    // Regenerating replaces all eight, so the old first code leaving the screen
    // is what says the new list has arrived.
    $page->click('button:has-text("Regenerate Codes")')
        ->assertDontSee($codesBefore[0]);

    $codesAfter = twoFactorRecoveryCodesOf($user);

    expect($codesAfter)->toHaveCount(8)
        ->and($codesAfter)->not->toBe($codesBefore);

    $page->assertSee($codesAfter[0])
        ->assertNoJavascriptErrors();
});

it('challenges a two-factor user at login and takes a recovery code', function () {
    [$user, $secret] = userWithConfirmedTwoFactorForBrowser();

    $page = visit('/login');

    AuthBrowserFlow::signIn($page, $user);

    $page->assertPathIs('/two-factor-challenge')
        ->assertSee('Authentication Code')
        ->fill('code', '000000')
        ->click('Continue')
        ->assertSee('The provided two factor authentication code was invalid')
        ->assertPathIs('/two-factor-challenge');

    // Reloaded rather than retyped: the rejected digits stay in the six slots
    // after the error, because the form's `resetOnError` clears Inertia's data
    // and not the page's own `code` state. Described in the pull request.
    $page->navigate('/two-factor-challenge')
        ->fill('code', app(Google2FA::class)->getCurrentOtp($secret))
        ->click('Continue')
        ->assertSee('Dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    AuthBrowserFlow::signOut($page);

    $page = visit('/login');

    AuthBrowserFlow::signIn($page, $user);

    $page->assertSee('Authentication Code')
        ->click('login using a recovery code')
        ->assertSee('emergency recovery codes')
        ->fill('recovery_code', 'aaaaaaaaaa-bbbbbbbbbb')
        ->click('Continue')
        ->assertSee('Dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    // Fortify swaps a used code for a fresh one rather than shortening the list,
    // so what proves it was spent is that it is no longer there.
    expect(twoFactorRecoveryCodesOf($user))
        ->toHaveCount(2)
        ->not->toContain('aaaaaaaaaa-bbbbbbbbbb')
        ->toContain('cccccccccc-dddddddddd');
});

it('disables two-factor so the password alone signs in again', function () {
    [$user] = userWithConfirmedTwoFactorForBrowser();

    $page = visit('/login');

    AuthBrowserFlow::signIn($page, $user);

    // Straight past the challenge with a recovery code; getting in is not what
    // this test is about.
    $page->assertSee('Authentication Code')
        ->click('login using a recovery code')
        ->fill('recovery_code', 'aaaaaaaaaa-bbbbbbbbbb')
        ->click('Continue')
        ->assertSee('Dashboard');

    $page = visit('/settings/two-factor');

    $page->assertSee('Confirm your password')
        ->fill('password', AuthBrowserFlow::PASSWORD)
        ->click('@confirm-password-button')
        ->assertSee('Manage your two-factor authentication settings')
        ->assertSee('Enabled')
        ->click('Disable 2FA')
        ->assertSee('When you enable two-factor authentication')
        ->assertSee('Disabled')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    AuthBrowserFlow::signOut($page);

    AuthBrowserFlow::signInAndOpenDashboard($user)
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});
