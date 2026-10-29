<?php

namespace App\Support;

/**
 * Where an OAuth redirect URI sends the user, as shown to them. App names are
 * chosen by whoever registers the app, so the redirect is what identifies it.
 */
class RedirectTarget
{
    /**
     * `scheme://host[:port]` for a URI with a host, or the whole URI when it
     * has none (a custom scheme such as `myapp:callback`).
     */
    public static function display(string $uri): string
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $uri;
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }
}
