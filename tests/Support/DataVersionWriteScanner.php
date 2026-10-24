<?php

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Finds the writes that slip past model events, so the data-version bump has
 * to be made by hand: quiet saves, upserts and inserts, builder and relation
 * mass updates/deletes, pivot syncs and raw `DB::` writes.
 *
 * It is a text scan, so it over-reports (a service method called `update()`
 * looks like a builder write) and the allowlist says why each hit is fine. A
 * builder kept in a variable and written later (`$query->update()`) is not
 * seen.
 *
 * @phpstan-type Hit array{file: string, line: int, statement: string}
 */
final class DataVersionWriteScanner
{
    /**
     * Tables behind the models that use BumpsDataVersion, their pivots, and
     * the exchange rates every cached figure is converted with.
     */
    public const array WATCHED_TABLES = [
        'account_balances',
        'accounts',
        'budget_category',
        'budget_label',
        'budget_periods',
        'budget_transactions',
        'budgets',
        'categories',
        'credit_card_details',
        'exchange_rates',
        'label_transaction',
        'labels',
        'loan_details',
        'real_estate_details',
        'savings_goal_periods',
        'savings_goals',
        'transactions',
        'user_settings',
    ];

    private const string PATTERN = '/'
        .'\b(?:insertOrIgnore|upsert|insertGetId|insertUsing|updateOrInsert|saveQuietly|updateQuietly|deleteQuietly|forceDeleteQuietly|restoreQuietly|withoutEvents)\s*\('
        .'|\)\s*\??->\s*(?:update|delete|forceDelete|restore|increment|decrement|incrementEach|decrementEach|insert|sync|syncWithoutDetaching|attach|detach|toggle|updateExistingPivot)\s*\('
        .'|\b[A-Z]\w*::(?:insert|truncate)\s*\('
        .'|\bDB::(?:update|delete|insert|statement|unprepared|affectingStatement)\s*\('
        .'|\bDB::table\(\s*(?<table>[^)]*)\)'
        .'/';

    /**
     * @return list<Hit>
     */
    public static function scan(string $directory, string $basePath): array
    {
        $hits = [];
        $basePath = realpath($basePath) ?: $basePath;

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            $relativePath = ltrim(str_replace($basePath, '', $file->getRealPath()), '/');

            foreach (self::scanSource(self::withoutComments($file->getContents())) as [$line, $statement]) {
                $hits[] = ['file' => $relativePath, 'line' => $line, 'statement' => $statement];
            }
        }

        return $hits;
    }

    /**
     * The hits no allowlist entry explains.
     *
     * @param  list<Hit>  $hits
     * @param  array<string, array<string, string>>  $allowlist  file => statement => where the bump happens
     * @return list<Hit>
     */
    public static function unlisted(array $hits, array $allowlist): array
    {
        return array_values(array_filter(
            $hits,
            fn (array $hit): bool => ! isset($allowlist[$hit['file']][$hit['statement']]),
        ));
    }

    /**
     * Allowlist entries that no longer match any hit, as "file: statement".
     *
     * @param  list<Hit>  $hits
     * @param  array<string, array<string, string>>  $allowlist
     * @return list<string>
     */
    public static function stale(array $hits, array $allowlist): array
    {
        $seen = [];

        foreach ($hits as $hit) {
            $seen[$hit['file']."\0".$hit['statement']] = true;
        }

        $stale = [];

        foreach ($allowlist as $file => $statements) {
            foreach (array_keys($statements) as $statement) {
                if (! isset($seen[$file."\0".$statement])) {
                    $stale[] = "{$file}: {$statement}";
                }
            }
        }

        return $stale;
    }

    /**
     * @return list<array{int, string}> line and statement of each hit
     */
    private static function scanSource(string $source): array
    {
        preg_match_all(self::PATTERN, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $found = [];

        foreach ($matches as $match) {
            [$text, $offset] = $match[0];
            $table = $match['table'][0] ?? null;

            if ($table !== null && self::isUnwatchedTableLiteral($table)) {
                continue;
            }

            $found[] = [
                substr_count($source, "\n", 0, $offset + strlen($text)) + 1,
                self::statementAt($source, $offset + strlen($text)),
            ];
        }

        return $found;
    }

    /**
     * Blank out comments, keeping their line breaks so line numbers hold: a
     * write mentioned in prose is not a write.
     */
    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private static function isUnwatchedTableLiteral(string $argument): bool
    {
        if (preg_match('/^\s*[\'"]([a-z_]+)[\'"]\s*$/', $argument, $literal) !== 1) {
            return false;
        }

        return ! in_array($literal[1], self::WATCHED_TABLES, true);
    }

    /**
     * The statement up to the end of the match, whitespace collapsed: from
     * the previous `;`, `{` or `}` on. Line numbers move with every edit, the
     * statement only when the write itself changes.
     */
    private static function statementAt(string $source, int $end): string
    {
        $head = substr($source, 0, $end);
        $start = max(strrpos($head, ';'), strrpos($head, '{'), strrpos($head, '}'));

        return trim((string) preg_replace('/\s+/', ' ', substr($head, $start === false ? 0 : $start + 1)));
    }
}
