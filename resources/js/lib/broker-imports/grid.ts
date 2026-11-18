import { type BrokerGrid } from '@/lib/broker-imports/types';
import { parseAmount, parseDate } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { DateFormat } from '@/types/import';
import { formatLocalDate } from '@/utils/date';

/** How far down a file the header row is looked for, past any title rows. */
export const HEADER_SCAN_ROWS = 15;

/** The currency a row is in when the file does not say. */
export const DEFAULT_CURRENCY = 'EUR';

/** The trimmed text of a cell, '' when it is empty. */
export function cellString(cell: unknown): string {
    return cell === null || cell === undefined ? '' : String(cell).trim();
}

/** The first row, within the scan window, whose normalised cells pass the test. */
export function findHeaderRow(
    grid: BrokerGrid,
    matches: (names: string[]) => boolean,
): number {
    const limit = Math.min(grid.length, HEADER_SCAN_ROWS);

    for (let index = 0; index < limit; index++) {
        if (matches((grid[index] ?? []).map(normalizeText))) {
            return index;
        }
    }

    return -1;
}

/** Each normalised header name to its column index, the first one winning. */
export function columnIndexes(header: unknown[]): Map<string, number> {
    const indexes = new Map<string, number>();

    header.forEach((cell, index) => {
        const name = normalizeText(cell);

        if (name && !indexes.has(name)) {
            indexes.set(name, index);
        }
    });

    return indexes;
}

const SPANISH_THOUSANDS_ONLY = /^[-+]?\d{1,3}(\.\d{3})+$/;

/**
 * A Spanish-formatted number ("1.234,56", "-62,35"), or a numeric cell as it
 * is. "1.850" is one thousand eight hundred and fifty: with no comma, dots in
 * groups of three are thousands, which the generic parser would read as a
 * decimal point.
 */
export function parseSpanishNumber(cell: unknown): number | null {
    if (typeof cell === 'number') {
        return cell;
    }

    const text = cellString(cell);

    return parseAmount(
        SPANISH_THOUSANDS_ONLY.test(text) ? text.replace(/\./g, '') : text,
    );
}

/** A day cell (`YYYY-MM-DD`, `DD/MM/YYYY` or a spreadsheet serial) as `YYYY-MM-DD`. */
export function parseDay(cell: unknown): string | null {
    if (typeof cell !== 'number' && typeof cell !== 'string') {
        return null;
    }

    const format = /^\s*\d{4}[-/.]/.test(String(cell))
        ? DateFormat.YearMonthDay
        : DateFormat.DayMonthYear;
    const date = parseDate(cell, format);

    return date ? formatLocalDate(date) : null;
}
