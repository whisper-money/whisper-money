<?php

namespace App\Support;

use Laravel\Passport\Client;

/**
 * The OAuth apps Whisper Money vouches for (`mcp.verified_apps`). Anyone can
 * register a client under any name, so a client is never verified by its
 * name: it is verified as an app only when every redirect URI it registered
 * can deliver the authorization code to that app's own hosts and nowhere
 * else. A spoofed registration then gains nothing, since the code still goes
 * to the real app.
 */
class VerifiedOAuthApps
{
    /**
     * @param  array<string, list<string>>  $apps  Verified app name => the exact hosts it redirects to.
     */
    public function __construct(private array $apps) {}

    public static function fromConfig(): self
    {
        return new self(config('mcp.verified_apps', []));
    }

    /**
     * The verified name of the client, or null when it is not verified (or
     * has been deleted).
     */
    public function nameOf(?Client $client): ?string
    {
        return $this->nameFor($client?->getAttribute('redirect_uris') ?? []);
    }

    /**
     * The verified app every one of the redirect URIs belongs to, or null.
     *
     * @param  list<string>  $redirectUris
     */
    public function nameFor(array $redirectUris): ?string
    {
        if ($redirectUris === []) {
            return null;
        }

        foreach ($this->apps as $name => $hosts) {
            if (array_all($redirectUris, fn (string $uri): bool => $this->reachesOnly($uri, $hosts))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether the URI is `https` on the default port, with no credentials,
     * and its host is exactly one of the hosts (no subdomains).
     *
     * @param  list<string>  $hosts
     */
    private function reachesOnly(string $uri, array $hosts): bool
    {
        $parts = parse_url($uri);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true);
    }
}
