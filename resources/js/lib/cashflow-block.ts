import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import {
    type CashflowBlock,
    type TransactionFilters,
} from '@/types/transaction';
import { formatLocalDate } from '@/utils/date';
import {
    endOfMonth,
    endOfQuarter,
    endOfYear,
    startOfDay,
    startOfMonth,
    startOfQuarter,
    startOfYear,
} from 'date-fns';

export const CASHFLOW_BLOCKS: readonly CashflowBlock[] = [
    'fixed',
    'variable',
    'general',
    'saved',
    'invested',
];

// Each block reads apart from its neighbours by lightness, not only by hue, so
// the bars still split for anyone who cannot tell rose from emerald.
export const CASHFLOW_BLOCK_TONES: Record<CashflowBlock, string> = {
    fixed: 'bg-rose-900 dark:bg-rose-700',
    variable: 'bg-rose-500 dark:bg-rose-400',
    general: 'bg-neutral-400 dark:bg-neutral-500',
    saved: 'bg-emerald-700 dark:bg-emerald-600',
    invested: 'bg-blue-700 dark:bg-blue-500',
};

/**
 * Untranslated labels (run them through `__()`) and the swatch colour each
 * block has on the cashflow screen.
 */
export const CASHFLOW_BLOCK_META: Record<
    CashflowBlock,
    { label: string; swatchClassName: string }
> = {
    fixed: {
        label: 'Fixed expense',
        swatchClassName: CASHFLOW_BLOCK_TONES.fixed,
    },
    variable: {
        label: 'Variable expense',
        swatchClassName: CASHFLOW_BLOCK_TONES.variable,
    },
    general: {
        label: 'General expense',
        swatchClassName: CASHFLOW_BLOCK_TONES.general,
    },
    saved: {
        label: 'Saved (cashflow)',
        swatchClassName: CASHFLOW_BLOCK_TONES.saved,
    },
    invested: {
        label: 'Invested',
        swatchClassName: CASHFLOW_BLOCK_TONES.invested,
    },
};

/**
 * The block a `cashflow_block` URL param names, or null for anything else.
 */
export function cashflowBlockFromParam(value: unknown): CashflowBlock | null {
    return typeof value === 'string' &&
        CASHFLOW_BLOCKS.includes(value as CashflowBlock)
        ? (value as CashflowBlock)
        : null;
}

/**
 * The `cashflow_block` param a filter set sends. A block only means
 * something over a period, and the backend rejects one without both dates,
 * so clearing either date drops the block too.
 */
export function cashflowBlockParam(
    filters: TransactionFilters,
): CashflowBlock | null {
    return filters.cashflowBlock && filters.dateFrom && filters.dateTo
        ? filters.cashflowBlock
        : null;
}

/**
 * The filters with the block dropped once either date is gone, so the chip
 * never shows a block the list is no longer narrowed to.
 */
export function withoutDatelessCashflowBlock(
    filters: TransactionFilters,
): TransactionFilters {
    return filters.cashflowBlock && !cashflowBlockParam(filters)
        ? { ...filters, cashflowBlock: null }
        : filters;
}

/**
 * The transactions list showing exactly the rows behind one cashflow block
 * over a range. Dates are `YYYY-MM-DD` strings or local dates.
 */
export function cashflowBlockTransactionsUrl(
    block: CashflowBlock,
    from: Date | string,
    to: Date | string,
): string {
    return transactionsIndex.url({
        query: {
            cashflow_block: block,
            date_from: typeof from === 'string' ? from : formatLocalDate(from),
            date_to: typeof to === 'string' ? to : formatLocalDate(to),
        },
    });
}

export type QuickPeriod = 'month' | 'quarter' | 'year';

export const QUICK_PERIODS: QuickPeriod[] = ['month', 'quarter', 'year'];

const QUICK_PERIOD_BOUNDS: Record<
    QuickPeriod,
    [start: (date: Date) => Date, end: (date: Date) => Date]
> = {
    month: [startOfMonth, endOfMonth],
    quarter: [startOfQuarter, endOfQuarter],
    year: [startOfYear, endOfYear],
};

/**
 * The calendar month, quarter or year containing `anchor`, both ends at
 * local midnight like the dates the date filter holds.
 */
export function quickPeriodRange(
    period: QuickPeriod,
    anchor: Date,
): { from: Date; to: Date } {
    const [start, end] = QUICK_PERIOD_BOUNDS[period];

    return { from: start(anchor), to: startOfDay(end(anchor)) };
}

/**
 * Which quick period, if any, a date range is exactly.
 */
export function matchingQuickPeriod(
    from: Date | null,
    to: Date | null,
): QuickPeriod | null {
    if (!from || !to) {
        return null;
    }

    return (
        QUICK_PERIODS.find((period) => {
            const range = quickPeriodRange(period, from);

            return (
                formatLocalDate(range.from) === formatLocalDate(from) &&
                formatLocalDate(range.to) === formatLocalDate(to)
            );
        }) ?? null
    );
}
