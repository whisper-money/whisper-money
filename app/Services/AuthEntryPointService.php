<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Cookie as HttpFoundationCookie;
use Symfony\Component\HttpFoundation\Response;

class AuthEntryPointService
{
    private const COOKIE_NAME = 'whisper_money_returning_user';

    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    /**
     * Routes that end in a redirect to another domain: the OAuth client once
     * an authorization is granted, and Stripe's billing portal.
     */
    private const OFF_SITE_DESTINATIONS = ['passport.authorizations.authorize', 'settings.billing.portal'];

    /**
     * @api Invoked dynamically via the container in bootstrap/app.php's
     *      redirectGuestsTo callback.
     */
    public function guestRedirectRoute(Request $request): string
    {
        // Connecting ChatGPT or Claude needs an account that already exists on
        // the Standard plan, so an OAuth authorization lands on the login form rather
        // than the sign-up form a first visit would otherwise get. So does an
        // emailed import link: only an existing account is ever sent one, and
        // it is usually opened on a computer that has never seen the app, and
        // so are the billing links in the failed-payment emails.
        if (
            $this->hasAuthenticatedBefore($request)
            || ! config('auth.registration_enabled')
            || $request->routeIs('passport.authorizations.authorize', 'onboarding.import-link.show', 'settings.billing', 'settings.billing.portal')
        ) {
            return route('login');
        }

        return route('register');
    }

    /**
     * Send a user who just signed in to where they were heading. The login form
     * posts through Inertia, which cannot finish an OAuth authorization: one
     * already granted redirects on to the client's own domain, a cross-origin
     * hop the browser blocks (the user is left on the login page with no
     * error), and a new one answers with the consent page, which is not an
     * Inertia page. So that destination is reached with a full page visit, and
     * so is the billing portal a failed-payment email links to, which
     * redirects on to Stripe.
     */
    public function redirectToIntended(): Response
    {
        $redirect = redirect()->intended(Fortify::redirects('login'));

        $leavesTheApp = collect(self::OFF_SITE_DESTINATIONS)
            ->contains(fn (string $route): bool => str_starts_with($redirect->getTargetUrl(), route($route)));

        return $leavesTheApp ? Inertia::location($redirect) : $redirect;
    }

    public function queueReturningUserCookie(): void
    {
        Cookie::queue($this->makeReturningUserCookie());
    }

    private function hasAuthenticatedBefore(Request $request): bool
    {
        return filter_var($request->cookie(self::COOKIE_NAME), FILTER_VALIDATE_BOOL);
    }

    private function makeReturningUserCookie(): HttpFoundationCookie
    {
        return Cookie::make(
            self::COOKIE_NAME,
            '1',
            self::COOKIE_MINUTES,
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }
}
