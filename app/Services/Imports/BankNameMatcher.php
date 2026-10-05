<?php

namespace App\Services\Imports;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds the bank behind an account name a full import brings in. Another app's
 * account names are the user's own aliases ("Wise Personal", "BBVA Conjunta"),
 * so a name matches a bank it equals or one it contains as whole words, case
 * and accents aside. Nothing is created: no match leaves the account without a
 * bank, which the user can still pick by hand.
 */
class BankNameMatcher
{
    /** Shorter bank names would match inside too many aliases to mean anything. */
    private const MIN_CONTAINED_LENGTH = 3;

    /**
     * @param  list<string>  $names
     * @return array<string, Bank|null> keyed by the name as given
     */
    public function match(User $user, array $names): array
    {
        $banks = Bank::query()
            ->availableForUser($user)
            ->get(['id', 'name', 'logo', 'user_id'])
            ->map(fn (Bank $bank): array => ['bank' => $bank, 'normalized' => self::normalize($bank->name)])
            ->filter(fn (array $entry): bool => $entry['normalized'] !== '')
            // Longest first, so "Wise Business" beats "Wise" when both fit.
            ->sortByDesc(fn (array $entry): int => mb_strlen($entry['normalized']))
            ->values();

        $matches = [];

        foreach ($names as $name) {
            $matches[$name] = $this->find($banks, self::normalize($name));
        }

        return $matches;
    }

    /**
     * @param  Collection<int, array{bank: Bank, normalized: non-empty-string}>  $banks
     */
    private function find(Collection $banks, string $alias): ?Bank
    {
        if ($alias === '') {
            return null;
        }

        $exact = $banks->first(fn (array $entry): bool => $entry['normalized'] === $alias);

        if ($exact !== null) {
            return $exact['bank'];
        }

        $contained = $banks->first(fn (array $entry): bool => mb_strlen($entry['normalized']) >= self::MIN_CONTAINED_LENGTH
            && preg_match('/(^| )'.preg_quote($entry['normalized'], '/').'( |$)/u', $alias) === 1);

        return $contained['bank'] ?? null;
    }

    /**
     * A bank name as the matcher compares it: lower case, no accents, words
     * separated by single spaces. Shared with the import's own bank creation,
     * so "Lares" and "LARES" are one bank there too.
     */
    public static function normalize(string $value): string
    {
        return Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
