import { type FullImportSource } from '@/types/full-import';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** A count the way the reader's region writes it: 2,403 or 2.403. */
export function formatCount(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
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
