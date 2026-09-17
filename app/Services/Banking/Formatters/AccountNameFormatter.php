<?php

namespace App\Services\Banking\Formatters;

/**
 * What to call an account a bank just handed over.
 *
 * Open banking gives two candidate names and the obvious one is the wrong one.
 * `name` is whose account it is — Sabadell returns the holder for every account
 * on the connection — so five accounts arrive as five identical rows on the one
 * screen that asks the user which of them to keep. `product` is what the bank
 * itself calls the account ("CUENTA EXPANSION", "CUENTA AVANZA"), which is the
 * name on the bank's own consent screen and the one the user recognises.
 *
 * Banks send that product shouting, so it is title-cased on the way in — but
 * only when there is no lowercase in it at all, so a name a bank already cased
 * properly ("Cuenta Nómina") is passed through untouched.
 */
class AccountNameFormatter
{
    /**
     * @param  array<string, mixed>  $account  The provider's account payload.
     * @param  string  $fallback  Used when the bank named the account nothing at all.
     */
    public static function format(array $account, string $fallback): string
    {
        foreach ([$account['product'] ?? null, $account['name'] ?? null] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return self::normalizeCase($candidate);
            }
        }

        // The IBAN is a last resort and is not a name: it is left exactly as the
        // bank wrote it, because a title-cased country code is not an IBAN.
        $iban = trim((string) ($account['account_id']['iban'] ?? ''));

        return $iban !== '' ? $iban : $fallback;
    }

    /** Shouted names, quietened; anything already mixed case is left alone. */
    private static function normalizeCase(string $name): string
    {
        return preg_match('/\p{Ll}/u', $name) === 1
            ? $name
            : mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }
}
