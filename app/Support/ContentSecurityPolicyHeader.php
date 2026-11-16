<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Adds directives to a response's Content-Security-Policy (or its
 * Report-Only twin) without dropping the ones another middleware already set,
 * so the response keeps one header of each. A directive both set keeps the
 * value that was there first: the route's own is the more specific one.
 */
final class ContentSecurityPolicyHeader
{
    private const string Header = 'Content-Security-Policy';

    public const string ReportOnlyHeader = 'Content-Security-Policy-Report-Only';

    /**
     * @param  array<string, string>  $directives  name => value, e.g. ['frame-ancestors' => "'none'"]
     */
    public static function merge(Response $response, array $directives, string $header = self::Header): void
    {
        $merged = self::parse((string) $response->headers->get($header, ''));

        foreach ($directives as $name => $value) {
            $merged[$name] ??= $value;
        }

        $response->headers->set($header, implode('; ', array_map(
            fn (string $name, string $value): string => trim("{$name} {$value}"),
            array_keys($merged),
            $merged,
        )));
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $policy): array
    {
        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), 2) ?: [];

            if (($parts[0] ?? '') !== '') {
                $directives[strtolower($parts[0])] = $parts[1] ?? '';
            }
        }

        return $directives;
    }
}
