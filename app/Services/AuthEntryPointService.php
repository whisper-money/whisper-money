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
     * @api Invoked dynamically via the container in bootstrap/app.php's
     *      redirectGuestsTo callback.
     */
    public function guestRedirectRoute(Request $request): string
    {
        // Connecting ChatGPT or Claude needs an account that already exists on
        // the Pro plan, so an OAuth authorization lands on the login form rather
        // than the sign-up form a first visit would otherwise get.
        if (
            $this->hasAuthenticatedBefore($request)
            || ! config('auth.registration_enabled')
            || $request->routeIs('passport.authorizations.authorize')
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
     * Inertia page. So that destination is reached with a full page visit.
     */
    public function redirectToIntended(): Response
    {
        $redirect = redirect()->intended(Fortify::redirects('login'));

        return str_starts_with($redirect->getTargetUrl(), route('passport.authorizations.authorize'))
            ? Inertia::location($redirect)
            : $redirect;
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
