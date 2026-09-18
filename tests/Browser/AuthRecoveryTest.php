<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\AuthBrowserFlow;

/**
 * The ways a reader gets back into an account they still own: the emailed reset
 * link, the verification link, a session that died while the screen stayed open,
 * and the log out that is meant to end it.
 *
 * The HTTP side of each of these already has coverage under tests/Feature/Auth.
 * What only a browser proves is that the page in front of the reader carries the
 * flow through — the form posts, the status message appears, and the redirect
 * lands somewhere real.
 */
beforeEach(function () {
    // None of these flows are about billing, and the paywall would otherwise
    // have a say in where a signed-in user lands.
    config(['subscriptions.enabled' => false]);
});

it('resets a forgotten password from the emailed link', function () {
    Notification::fake();

    $user = AuthBrowserFlow::user(['email' => 'forgetful@example.com']);

    $page = visit('/forgot-password');

    $page->assertSee('Forgot password')
        ->fill('email', 'forgetful@example.com')
        ->click('@email-password-reset-link-button')
        ->assertSee('We have emailed your password reset link')
        ->assertNoJavascriptErrors();

    $token = null;

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $page = visit('/reset-password/'.$token.'?email='.urlencode($user->email));

    $page->assertSee('Reset password')
        ->fill('password', 'a-fresh-start-2026')
        ->fill('password_confirmation', 'a-fresh-start-2026')
        ->click('@reset-password-button')
        ->assertSee('Your password has been reset')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();

    expect(Hash::check('a-fresh-start-2026', $user->refresh()->password))->toBeTrue();

    // The old password is gone rather than merely unused, which is the whole
    // point of a reset for an account whose old one leaked.
    $page->fill('email', $user->email)
        ->fill('password', AuthBrowserFlow::PASSWORD)
        ->click('@login-button')
        ->assertSee('These credentials do not match our records');

    $page->fill('email', $user->email)
        ->fill('password', 'a-fresh-start-2026')
        ->click('@login-button')
        ->assertSee('Dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});

it('verifies a new email address from the signed link', function () {
    Notification::fake();

    // The language is pinned because this user has never made a request: without
    // it the app would take whatever Accept-Language the browser happens to send.
    $user = User::factory()->notOnboarded()->unverified()->withoutTwoFactor()->create([
        'locale' => 'en',
    ]);

    $page = visit('/login');

    AuthBrowserFlow::signIn($page, $user);

    $page->assertSee('Verify email')
        ->assertPathIs('/email/verify')
        ->click('Resend verification email')
        ->assertSee('A new verification link has been sent')
        ->assertNoJavascriptErrors();

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

    $page = visit(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]));

    // A brand new account has nothing to show on the dashboard yet, so a
    // verified one is handed straight to onboarding.
    $page->assertSee('Find out where your money actually went')
        ->assertPathIs('/onboarding')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('reloads to the login page when the session died under an open screen', function () {
    // The accounts screen prices every balance in the reader's own currency, so
    // it asks for a rate even when there is nothing to convert.
    fakeCurrencyApi();

    $user = AuthBrowserFlow::user();

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Stale Screen Account',
        'currency_code' => $user->currency_code,
        'type' => 'checking',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->startOfMonth()->toDateString(),
        'balance' => 123456,
    ]);

    AuthBrowserFlow::signInAndOpenDashboard($user);

    // The screen a reader leaves open: loaded while the session was alive, with
    // a write behind a button they have not pressed yet.
    $page = visit('/accounts');

    // The balances arrive as a deferred prop, and the dialog reads the last one
    // before it lets anyone type. Both of those reads belong on the near side of
    // the session dying, so the write below is the first request to fail — which
    // is why the amount field, and not just the dialog, is asserted here.
    $page->assertSee('Stale Screen Account')
        ->assertSee('$1,234.56')
        ->click('Update balance')
        ->assertSee('Set the balance for this account on a specific date.')
        ->assertPresent('#balance-amount');

    // The session dies underneath it, which is what SESSION_LIFETIME passing
    // does to a tab nobody closed.
    //
    // `Auth::forgetGuards()` is not enough here, and it was tried: this reader
    // signed in through the form, so the id lives in the session itself and the
    // guard that replaces the forgotten one reads it straight back. Taking the
    // id out of the session is what actually leaves the browser holding a
    // cookie the server no longer knows anyone by.
    Auth::guard('web')->logout();

    // Saving now answers 401, and the recovery reloads rather than leaving the
    // reader poking a dead dialog. See resources/js/lib/session-expiry-recovery.ts.
    $page->click('Save')
        ->assertSee('Log in to your account')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();

    expect(AccountBalance::query()->where('account_id', $account->id)->count())->toBe(1);
});

it('ends the session from the user menu', function () {
    $user = AuthBrowserFlow::user();

    $page = AuthBrowserFlow::signInAndOpenDashboard($user);

    $page->assertPathIs('/dashboard');

    AuthBrowserFlow::signOut($page);

    $page->assertSee('Whisper Money')
        ->assertPathIs('/');

    // Navigated rather than visited: `visit()` opens a fresh browser context,
    // which would throw away the cookie that tells the app this browser has
    // signed in before — and with it the reason a guest is sent to the login
    // page instead of the sign-up one.
    $page->navigate('/dashboard')
        ->assertSee('Log in to your account')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();
});
