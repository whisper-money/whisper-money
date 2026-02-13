<?php

namespace App\Services\Banking\Formatters;

class BbvaFormatter implements BankFormatter
{
    /** @var string[] */
    private const ACRONYMS = [
        'SEPA', 'IBAN', 'CPVR', 'BIZUM', 'ATM', 'BBVA',
    ];

    /** @var string[] */
    private const PRESERVED_PATTERNS = [
        'S.A.', 'S.L.', 'S.L.U.',
    ];

    /** @var string[] */
    private const STOPWORDS = [
        'de', 'del', 'la', 'las', 'los', 'el', 'en', 'y', 'a', 'al', 'por', 'con', 'para',
    ];

    public function matches(string $bankName): bool
    {
        return mb_strtolower($bankName) === 'bbva';
    }

    public function format(string $description): string
    {
        if (! $this->isMostlyUppercase($description)) {
            return $description;
        }

        $description = str_replace('//', '/', $description);
        $description = preg_replace('/\s+/', ' ', $description);

        $segments = explode('/', $description);
        $formatted = array_map(fn (string $segment) => $this->formatSegment(trim($segment)), $segments);

        return implode(' / ', array_filter($formatted, fn (string $s) => $s !== ''));
    }

    private function isMostlyUppercase(string $text): bool
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $text);

        if ($letters === '' || $letters === null) {
            return false;
        }

        $uppercase = preg_replace('/[^A-Z]/', '', $letters);

        return mb_strlen($uppercase) / mb_strlen($letters) > 0.7;
    }

    private function formatSegment(string $segment): string
    {
        if ($segment === '') {
            return '';
        }

        $words = preg_split('/\s+/', $segment);
        $formatted = [];

        foreach ($words as $index => $word) {
            $formatted[] = $this->formatWord($word, $index === 0);
        }

        return implode(' ', $formatted);
    }

    private function formatWord(string $word, bool $isFirst): string
    {
        if ($this->isReferenceNumber($word)) {
            return $word;
        }

        $upperWord = mb_strtoupper($word);

        foreach (self::PRESERVED_PATTERNS as $pattern) {
            if ($upperWord === str_replace('.', '', $pattern) || $upperWord === $pattern) {
                return $pattern;
            }
        }

        if (in_array($upperWord, self::ACRONYMS, true)) {
            return $upperWord;
        }

        $lower = mb_strtolower($word);

        if (! $isFirst && in_array($lower, self::STOPWORDS, true)) {
            return $lower;
        }

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }

    private function isReferenceNumber(string $word): bool
    {
        return (bool) preg_match('/\d/', $word);
    }
}
