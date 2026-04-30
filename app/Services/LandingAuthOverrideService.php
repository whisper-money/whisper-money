<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
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

        if (! $this->hasValidSignedOverride($request)) {
            return false;
        }

        $this->queueOverrideCookie();

        return true;
    }

    public function generateSignedUrl(int $days): string
    {
        $path = $this->signedPath(now()->addDays($days));

        return rtrim(config('app.url'), '/').$path;
    }

    /**
     * Generate a signed landing URL for a specific user lead.
     */
    public function generateInvitationUrl(string $leadId, int $days = 30): string
    {
        $path = $this->signedPath(now()->addDays($days), ['lead' => $leadId]);

        return rtrim(config('app.url'), '/').$path;
    }

    /**
     * @param  array<string, scalar>  $extraParameters
     */
    public function signedPath(\DateTimeInterface|\DateInterval|int $expiration, array $extraParameters = []): string
    {
        $parameters = $extraParameters + [
            $this->queryParameter() => 1,
            'expires' => $this->availableAt($expiration),
        ];

        ksort($parameters);

        $signature = hash_hmac('sha256', $this->originalString('/', $parameters), $this->signingKey());

        return '/?'.Arr::query($parameters + ['signature' => $signature]);
    }

    private function queueOverrideCookie(): void
    {
        Cookie::queue($this->makeOverrideCookie());
    }

    private function makeOverrideCookie(): HttpFoundationCookie
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

        if ($this->signatureHasExpired($request)) {
            return false;
        }

        $parameters = $request->query();
        unset($parameters['signature']);

        foreach (config('landing.auth_override.ignore_signature_query_parameters', []) as $ignoredParameter) {
            unset($parameters[$ignoredParameter]);
        }

        ksort($parameters);

        $signature = (string) $request->query('signature', '');

        return hash_equals(
            hash_hmac('sha256', $this->originalString($request->getPathInfo(), $parameters), $this->signingKey()),
            $signature,
        );
    }

    private function signatureHasExpired(Request $request): bool
    {
        $expires = $request->query('expires');

        if (! is_numeric($expires)) {
            return true;
        }

        return (int) $expires < now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function originalString(string $path, array $parameters): string
    {
        $normalizedPath = $path === '' ? '/' : $path;
        $query = Arr::query($parameters);

        return rtrim($normalizedPath.'?'.$query, '?');
    }

    private function signingKey(): string
    {
        $key = app('config')->get('app.key');

        if (! is_string($key) || $key === '') {
            $url = URL::to('/');

            throw new \RuntimeException("Unable to sign landing auth URL for {$url} without app.key.");
        }

        return $key;
    }

    private function availableAt(\DateTimeInterface|\DateInterval|int $delay): int
    {
        if ($delay instanceof \DateTimeInterface) {
            return $delay->getTimestamp();
        }

        if ($delay instanceof \DateInterval) {
            return now()->add($delay)->getTimestamp();
        }

        return now()->addSeconds($delay)->getTimestamp();
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
