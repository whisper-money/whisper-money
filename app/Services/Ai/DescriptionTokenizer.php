<?php

namespace App\Services\Ai;

/**
 * Turns a free-text bank description into comparable, language-agnostic tokens
 * and isolates the distinctive ones — the part that stays constant across
 * "practically identical" variants of the same transaction. Structural noise
 * (words common across the corpus: "pago", "carte", a city, an operation type)
 * is dropped by document frequency rather than a hardcoded stopword list.
 *
 * Stateless and corpus-agnostic: callers supply the document frequency and the
 * noise threshold so the same logic serves both rule suggestions and learning
 * from a single corrected transaction.
 */
class DescriptionTokenizer
{
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * REFERENCE_MARKERS and MONTH_NAMES as a lookup, built on first use.
     *
     * @var array<string, int>|null
     */
    private static ?array $noise = null;

    /**
     * Words that only announce what follows (a reference, a mandate, a
     * number) and change nothing about who the counterparty is.
     */
    private const REFERENCE_MARKERS = [
        'ref', 'refs', 'referencia', 'reference', 'nref', 'mandato', 'mandate', 'num', 'numero', 'número', 'number', 'txn', 'trx', 'operacion', 'operación',
    ];

    /**
     * Month names and their usual abbreviations in the languages the app
     * speaks, which bank descriptions use to say which period a charge is for.
     * Names and abbreviations that are also words in merchant names ("mar",
     * "may", "ago", "mars", "mai") are left out.
     */
    private const MONTH_NAMES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'setiembre', 'octubre', 'noviembre', 'diciembre',
        'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
        'janvier', 'février', 'fevrier', 'avril', 'juin', 'juillet', 'août', 'aout', 'septembre', 'octobre', 'novembre', 'décembre', 'decembre',
        'ene', 'feb', 'abr', 'sep', 'sept', 'oct', 'nov', 'dic', 'jan', 'apr', 'jun', 'jul', 'aug', 'dec', 'fev', 'avr', 'jui', 'aou',
    ];

    /**
     * Split a description into lowercased word tokens, with digits and
     * punctuation stripped and very short tokens dropped.
     *
     * @return list<string>
     */
    public function tokens(string $value): array
    {
        $value = $this->normalize($value);
        $value = preg_replace('/[0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\s]+/u', ' ', $value) ?? $value;
        $value = $this->normalize($value);

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $value),
            fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH,
        ));
    }

    /**
     * The tokens that stay the same from one charge of a counterparty to the
     * next, in their original order: like tokens(), but every word that
     * carries a digit (an IBAN, a reference code, a date) goes whole rather
     * than leaving its letters behind (punctuation splits words first, so
     * "IBERDROLA-01/12" keeps its name), and so do reference markers and month
     * names. "RECIBO IBERDROLA REF 88412 01/11" and "Recibo Iberdrola ref.
     * 90233 - 01/12" both give `recibo iberdrola`.
     *
     * @return list<string>
     */
    public function stableTokens(string $value): array
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? $value;
        $value = preg_replace('/\S*\d\S*/u', ' ', $value) ?? $value;
        $noise = self::$noise ??= array_flip([...self::REFERENCE_MARKERS, ...self::MONTH_NAMES]);

        return array_values(array_filter(
            $this->tokens($value),
            fn (string $token): bool => ! isset($noise[$token]),
        ));
    }

    /**
     * Document frequency of each token across a corpus of descriptions: how many
     * descriptions each token appears in (counted once per description).
     *
     * @param  iterable<int, string|null>  $descriptions
     * @return array<string, int>
     */
    public function documentFrequency(iterable $descriptions): array
    {
        $frequency = [];

        foreach ($descriptions as $description) {
            foreach (array_unique($this->tokens((string) $description)) as $token) {
                $frequency[$token] = ($frequency[$token] ?? 0) + 1;
            }
        }

        return $frequency;
    }

    /**
     * The distinctive tokens of a description: those appearing in no more than
     * the noise threshold of the corpus, sorted for a stable key. If every token
     * is common (all noise), the full token set is kept as a fallback.
     *
     * @param  array<string, int>  $documentFrequency
     * @return list<string>
     */
    public function distinctiveTokens(string $value, array $documentFrequency, float $noiseThreshold): array
    {
        $tokens = $this->tokens($value);

        if ($tokens === []) {
            return [];
        }

        $distinctive = array_values(array_filter(
            $tokens,
            fn (string $token): bool => ($documentFrequency[$token] ?? 0) <= $noiseThreshold,
        ));

        if ($distinctive === []) {
            $distinctive = $tokens;
        }

        $distinctive = array_values(array_unique($distinctive));
        sort($distinctive);

        return $distinctive;
    }

    /**
     * The distinctive tokens joined into a single stable grouping key.
     *
     * @param  array<string, int>  $documentFrequency
     */
    public function distinctiveKey(string $value, array $documentFrequency, float $noiseThreshold): string
    {
        return implode(' ', $this->distinctiveTokens($value, $documentFrequency, $noiseThreshold));
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '');
    }
}
