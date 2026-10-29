<?php

namespace App\Http\Controllers\OAuth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * laravel/mcp's `POST /oauth/register` (RFC 7591), bound in its place, with
 * the redirect allowlist matched on the parsed URI rather than a string
 * prefix: a prefix such as `http://127.0.0.1:` also matches a URI whose user
 * info is `127.0.0.1` and whose real host is somewhere else. Every http(s)
 * redirect URI must carry no user info and its scheme, host and port must
 * match an entry of `mcp.redirect_domains` exactly (any port on loopback).
 * Custom schemes and the rest of the metadata are left to the package.
 */
class RegisterController extends OAuthRegisterController
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function __invoke(Request $request): JsonResponse
    {
        foreach ((array) $request->input('redirect_uris', []) as $index => $uri) {
            if (is_string($uri) && ! $this->isPermittedRedirectUri($uri)) {
                return response()->json([
                    'error' => 'invalid_redirect_uri',
                    'error_description' => "redirect_uris.{$index} is not a permitted redirect domain.",
                ], 400);
            }
        }

        return parent::__invoke($request);
    }

    protected function isLocalhostUrl(string $url): bool
    {
        $uri = parse_url($url);

        return is_array($uri)
            && ($uri['scheme'] ?? null) === 'http'
            && ! isset($uri['user'])
            && ! isset($uri['pass'])
            && in_array(strtolower($uri['host'] ?? ''), self::LOOPBACK_HOSTS, true);
    }

    private function isPermittedRedirectUri(string $value): bool
    {
        $uri = parse_url($value);

        if ($uri === false || isset($uri['user']) || isset($uri['pass'])) {
            return false;
        }

        if (! in_array($uri['scheme'] ?? null, ['http', 'https'], true)) {
            return true;
        }

        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        if (in_array('*', $domains, true)) {
            return true;
        }

        return collect($domains)->contains(fn (string $domain): bool => $this->matchesDomain($uri, $value, $domain));
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int}  $uri
     */
    private function matchesDomain(array $uri, string $value, string $domain): bool
    {
        $allowed = parse_url($domain);

        if (! is_array($allowed)) {
            return false;
        }

        if (in_array(strtolower($allowed['host'] ?? ''), self::LOOPBACK_HOSTS, true)) {
            return $this->isLocalhostUrl($value);
        }

        return ($uri['scheme'] ?? null) === ($allowed['scheme'] ?? null)
            && strtolower($uri['host'] ?? '') === strtolower($allowed['host'] ?? '')
            && ($uri['port'] ?? null) === ($allowed['port'] ?? null);
    }
}
