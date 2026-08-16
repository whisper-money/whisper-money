<?php

namespace App\Services\Banking;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * How long to stay off a provider that answered 429.
 *
 * Lives apart from SyncBankingConnectionJob because the syncer needs the same
 * answer: a rate limit on the balance call must not throw away a run whose
 * transactions already landed, so whoever catches it has to be able to say when
 * we may come back.
 *
 * Static because it is a pure reading of the response - no state, no collaborators,
 * and nothing worth faking in a test.
 */
final class RateLimitBackoff
{
    public static function isRateLimit(\Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 429;
    }

    public static function until(\Throwable $e): Carbon
    {
        $now = now();

        if ($e instanceof RequestException) {
            $retryAfter = $e->response->header('Retry-After');

            if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
                return $now->copy()->addSeconds((int) $retryAfter);
            }

            $body = $e->response->json();
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

            if (self::isExhaustedAccessAllowance($message)) {
                return $now->copy()->utc()->addDay()->startOfDay();
            }
        }

        // Default: back off one hour for a burst limit we know nothing else about.
        return $now->copy()->addHour();
    }

    /**
     * Whether the provider is reporting a spent allowance rather than a burst.
     *
     * PSD2 budgets unattended access per consent per day, so these do not come back
     * in an hour - they come back when the day does. Matched on the wordings the
     * banks actually send, counted over 45 days of banking_sync_logs: "[HUB046]
     * Allowed number of accesses exceeded for consent." (234), "Access exceeded"
     * (94), "Maximum daily access exceeded" (48), "The access on the account has
     * been exceeding the consented multiplicity per day." (37), "Daily PSU not
     * present consultation limit has been exceeded" (11), and a localised pair,
     * "CLO03941 - Operación no disponible. Has superado el número máximo de
     * accesos." (4) plus its Catalan twin (1).
     *
     * ponytail: prose matching, because there is nothing better to key on -
     * detail.error_name is `RateLimitException` for a spent daily allowance and for
     * a plain burst alike. If Enable Banking ever separates the two, key on that.
     * Note error_message only holds the first 120 bytes of the body, so a future
     * attempt at a structured field has to start by logging the whole thing.
     */
    private static function isExhaustedAccessAllowance(string $message): bool
    {
        return Str::contains($message, [
            'daily',
            'access exceeded',
            'accesses exceeded',
            'exceeding the consented',
            'máximo de accesos',
            'màxim d’accessos',
        ], ignoreCase: true);
    }
}
