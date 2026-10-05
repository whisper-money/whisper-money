<?php

namespace App\Services\Imports;

/**
 * The IBAN a file gives an account, kept only when it is a real one. Banktrack
 * exports them masked ("ES12 3456 7890 1234 5678 XXXX"), and a masked IBAN
 * stored as is would show as "•••• XXXX" everywhere the account appears.
 */
final class ImportIban
{
    /**
     * The IBAN without spaces, upper case, or null when it is masked or not
     * an IBAN at all: the wrong shape, or check digits that do not add up.
     */
    public static function normalize(?string $iban): ?string
    {
        $raw = (string) $iban;

        if (preg_match('/[*•]|X{2,}/iu', $raw) === 1) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/\s+/', '', $raw));

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $normalized) !== 1) {
            return null;
        }

        return self::checksumHolds($normalized) ? $normalized : null;
    }

    /**
     * ISO 13616: with the first four characters moved to the end and every
     * letter spelled as its number (A = 10 … Z = 35), the IBAN read as one
     * number leaves 1 when divided by 97. Done a digit at a time, since the
     * number is far longer than an integer holds.
     */
    private static function checksumHolds(string $iban): bool
    {
        $remainder = 0;

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
