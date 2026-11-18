import {
    type BrokerGrid,
    type Position,
    type Trade,
} from '@/lib/broker-imports/types';
import { parseAmount, parseDate, parseFile } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { DateFormat } from '@/types/import';
import { formatLocalDate } from '@/utils/date';

/** How far down a file the header row is looked for, past any title rows. */
export const HEADER_SCAN_ROWS = 15;

/** The rows of a file as `parseFile` read them, turned back from its columns. */
export function gridFromColumns(columns: unknown[][]): BrokerGrid {
    const height = Math.max(0, ...columns.map((column) => column.length));

    return Array.from({ length: height }, (_, rowIndex) =>
        columns.map((column) => column[rowIndex]),
    );
}

/** Reads a file into its grid, with the same reader the import drawer uses. */
export async function readBrokerGrid(file: File): Promise<BrokerGrid> {
    const { columns } = await parseFile(file);

    return gridFromColumns(columns);
}

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

/**
 * A short, stable hash of a text (cyrb53), for ids of rows the bank gives no
 * id to. Not for anything secret.
 */
export function stableHash(text: string): string {
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;

    for (let index = 0; index < text.length; index++) {
        const code = text.charCodeAt(index);
        h1 = Math.imul(h1 ^ code, 2654435761);
        h2 = Math.imul(h2 ^ code, 1597334677);
    }

    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507);
    h1 ^= Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507);
    h2 ^= Math.imul(h1 ^ (h1 >>> 13), 3266489909);

    return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}

/** Shares rounded away from float noise, so a closed position reads 0. */
function roundShares(shares: number): number {
    return Math.round(shares * 1e10) / 1e10;
}

/** One change to a holding: a trade, or shares received without paying. */
export type PositionChange = Omit<Position, 'shares' | 'invested'> & {
    /** Signed. */
    shares: number;
    /** Signed, in minor units. */
    invested: number;
};

/** A trade as the change it makes to its holding. */
export function tradeChange(trade: Trade): PositionChange {
    const sign = trade.side === 'buy' ? 1 : -1;

    return {
        symbol: trade.symbol,
        name: trade.name,
        assetClass: trade.assetClass,
        currency: trade.currency,
        shares: sign * trade.shares,
        invested: sign * trade.amount,
    };
}

/** What is held of each symbol, in the order the symbols first appear. */
export function positionsFrom(changes: PositionChange[]): Position[] {
    const bySymbol = new Map<string, Position>();

    for (const change of changes) {
        const position = bySymbol.get(change.symbol) ?? {
            ...change,
            shares: 0,
            invested: 0,
        };

        position.shares = roundShares(position.shares + change.shares);
        position.invested += change.invested;
        bySymbol.set(change.symbol, position);
    }

    return [...bySymbol.values()];
}
