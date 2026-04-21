<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Cookie as HttpFoundationCookie;

class AuthEntryPointService
{
    private const COOKIE_NAME = 'whisper_money_returning_user';

    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function __construct(private readonly LandingAuthOverrideService $landingAuthOverrideService) {}

    /**
     * @api
     */
    public function guestRedirectRoute(Request $request): string
    {
        if (
            $this->hasAuthenticatedBefore($request)
            || ! Features::enabled(Features::registration())
            || $this->landingAuthOverrideService->authButtonsHidden($request)
        ) {
            return route('login');
        }

        return route('register');
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
