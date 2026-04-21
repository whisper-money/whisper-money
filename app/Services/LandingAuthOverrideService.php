<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpFoundationCookie;

class LandingAuthOverrideService
{
    public function authButtonsHidden(Request $request): bool
    {
        if (! config('landing.hide_auth_buttons', false)) {
            return false;
        }

        return ! $this->allowsAuthentication($request);
    }

    public function allowsAuthentication(Request $request): bool
    {
        if (! config('landing.hide_auth_buttons', false)) {
            return true;
        }

        if ($request->boolean('force')) {
            return true;
        }

        if ($this->hasOverrideCookie($request)) {
            return true;
        }

        return $this->hasValidSignedOverride($request);
    }

    public function shouldQueueOverrideCookie(Request $request): bool
    {
        return config('landing.hide_auth_buttons', false)
            && $this->hasValidSignedOverride($request)
            && ! $this->hasOverrideCookie($request);
    }

    public function makeOverrideCookie(): HttpFoundationCookie
    {
        return Cookie::make(
            $this->cookieName(),
            '1',
            (int) config('landing.auth_override.cookie_minutes', 60 * 24 * 7),
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }

    private function hasValidSignedOverride(Request $request): bool
    {
        if (! $request->boolean($this->queryParameter())) {
            return false;
        }

        return $request->hasValidSignatureWhileIgnoring(
            config('landing.auth_override.ignore_signature_query_parameters', []),
        );
    }

    private function hasOverrideCookie(Request $request): bool
    {
        return filter_var($request->cookie($this->cookieName()), FILTER_VALIDATE_BOOL);
    }

    private function queryParameter(): string
    {
        return (string) config('landing.auth_override.query_parameter', 'signup');
    }

    private function cookieName(): string
    {
        return (string) config('landing.auth_override.cookie_name', 'landing_auth_override');
    }
}
