import { type FullImportSource, type ImportStats } from '@/types/full-import';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** A count the way the reader's region writes it: 2,403 or 2.403. */
export function formatCount(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/**
 * One of two already-translated phrases by count, for the strings that read
 * differently for one ("1 transaction") than for any other number. Both are
 * passed in as literal __() calls, so the translation check sees each.
 */
export function countLabel(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

/** "1 transaction", "2,403 transactions". */
export function transactionCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 transaction'),
        __(':count transactions', { count: formatCount(count, locale) }),
    );
}

/** "1 account", "6 accounts". */
export function accountCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 account'),
        __(':count accounts', { count: formatCount(count, locale) }),
    );
}

/** "1 category", "5 categories". */
export function categoryCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 category'),
        __(':count categories', { count: formatCount(count, locale) }),
    );
}

/** "1 daily balance", "398 daily balances". */
export function dailyBalanceCount(count: number, locale: string): string {
    return countLabel(
        count,
        __('1 daily balance'),
        __(':count daily balances', { count: formatCount(count, locale) }),
    );
}

/** "Jan 2024 – Sep 2026", or the one month when both ends share it. */
export function formatMonthRange(
    from: string,
    to: string,
    locale: string,
): string {
    const start = formatDate(from, 'MMM yyyy', locale);
    const end = formatDate(to, 'MMM yyyy', locale);

    return start === end ? start : `${start} – ${end}`;
}

/** The app the file came from, as the user knows it. */
export function sourceLabel(source: FullImportSource): string {
    return source === 'banktrack' ? 'Banktrack' : __('spreadsheet');
}

/** What a new account clashing with a connected one is called after. */
export function sourceSuffix(source: FullImportSource): string {
    return source === 'banktrack' ? 'Banktrack' : __('import');
}

/** A file size a person reads: 412 KB, 1.2 MB. */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * What happened, or is happening, to the transactions a file left
 * uncategorized, in words. Null when there is nothing worth saying.
 */
export function aiOutcomeNote(
    stats: ImportStats,
    locale: string,
): string | null {
    const uncategorized = stats.uncategorized ?? 0;
    const count = formatCount(uncategorized, locale);

    switch (stats.ai?.status) {
        case 'queued':
        case 'running':
            return countLabel(
                uncategorized,
                __(
                    'The AI is categorizing the transaction that arrived without a category. It takes a few minutes.',
                ),
                __(
                    'The AI is categorizing the :count transactions that arrived without a category. It takes a few minutes.',
                    { count },
                ),
            );
        case 'done':
            return countLabel(
                stats.ai.applied ?? 0,
                __('The AI categorized 1 transaction.'),
                __('The AI categorized :count transactions.', {
                    count: formatCount(stats.ai.applied ?? 0, locale),
                }),
            );
        case 'failed':
            return countLabel(
                uncategorized,
                __(
                    "The AI couldn't finish: 1 transaction stayed uncategorized.",
                ),
                __(
                    "The AI couldn't finish: :count transactions stayed uncategorized.",
                    { count },
                ),
            );
        case 'onboarding':
            return countLabel(
                uncategorized,
                __(
                    '1 transaction arrived without a category. You can categorize it in a moment.',
                ),
                __(
                    ':count transactions arrived without a category. You can categorize them in a moment.',
                    { count },
                ),
            );
        case 'unavailable':
            return countLabel(
                uncategorized,
                __(
                    '1 transaction stayed uncategorized. With a paid plan, the AI categorizes it for you.',
                ),
                __(
                    ':count transactions stayed uncategorized. With a paid plan, the AI categorizes them for you.',
                    { count },
                ),
            );
        default:
            return null;
    }
}
