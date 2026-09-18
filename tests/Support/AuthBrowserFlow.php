<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

/**
 * The steps every authentication browser test repeats.
 *
 * Signing in through the form rather than `actingAs()` is the point of these
 * flows: the session cookie and the "returning user" cookie only exist once a
 * login response has actually been through the browser, and the second of them
 * is what decides whether a guest is sent to /login or to /register.
 */
final class AuthBrowserFlow
{
    /**
     * The password `UserFactory` gives every user it makes.
     */
    public const string PASSWORD = 'password';

    /**
     * A verified, onboarded user with no two-factor authentication set up.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function user(array $attributes = []): User
    {
        return User::factory()->onboarded()->withoutTwoFactor()->create($attributes);
    }

    /**
     * Open the login page, sign in and wait for the dashboard.
     *
     * The whole journey for an account with nothing in its way, which is where
     * most of these tests start. Returns the page so the test carries on in the
     * same browser context: `visit()` opens a fresh one, and the cookie that
     * records this browser has signed in before does not survive that.
     */
    public static function signInAndOpenDashboard(User $user, string $password = self::PASSWORD)
    {
        $page = visit('/login');

        self::signIn($page, $user, $password);

        return $page->assertSee('Dashboard');
    }

    /**
     * Fill in the login form and submit it. The caller asserts on wherever the
     * account in question lands: the dashboard, the two-factor challenge or the
     * email verification notice.
     */
    public static function signIn($page, User $user, string $password = self::PASSWORD): void
    {
        $page->assertSee('Log in to your account')
            ->fill('email', $user->email)
            ->fill('password', $password)
            ->click('@login-button');
    }

    /**
     * Log out the way a reader does, through the user menu in the sidebar.
     */
    public static function signOut($page): void
    {
        $page->click('@sidebar-menu-button')
            ->click('@logout-button');
    }
}
