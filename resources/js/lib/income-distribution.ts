import { type CashflowSummary } from '@/hooks/use-cashflow-data';

export type DistributionSegmentKey =
    | 'fixed'
    | 'variable'
    | 'saved'
    | 'invested'
    | 'reserved'
    | 'free';

export interface DistributionSegment {
    key: DistributionSegmentKey;
    /** The true figure, which the legend shows even when the bar can't. */
    amount: number;
    /** Width on the bar, as a percentage of its scale; never negative. */
    width: number;
}

export interface IncomeDistribution {
    income: number;
    allocated: number;
    available: number;
    reserved: number;
    free: number;
    segments: DistributionSegment[];
    /** Widths of the "Allocated" and "Available" brackets above the bar. */
    allocatedWidth: number;
    availableWidth: number;
}

/** Expenses plus what was saved or invested. */
export function allocatedOf(summary: CashflowSummary): number {
    return summary.expense + summary.savings + summary.investments;
}

/** Income minus what was allocated: the Sankey's center node. */
export function availableOf(summary: CashflowSummary): number {
    return summary.net - summary.savings - summary.investments;
}

/** What is left of the available once the open budgets' margins are set aside. */
export function freeOf(summary: CashflowSummary): number {
    return availableOf(summary) - (summary.reserved ?? 0);
}

function widthOf(amount: number, scale: number): number {
    return scale > 0 ? (amount / scale) * 100 : 0;
}

/**
 * Splits the period's income into what the stacked bar draws.
 *
 * The bar is scaled to income, or to what was allocated when that is more
 * (a negative available): there is no room on an income-wide bar for money
 * that was never earned. A negative figure, such as a refund-heavy month's
 * fixed expenses, takes no width but keeps its amount for the legend. The
 * reserve can only fill what is available, and Free takes the rest.
 */
export function incomeDistribution(
    summary: CashflowSummary,
): IncomeDistribution {
    const variable = summary.variable_expense ?? 0;
    const fixed = summary.fixed_expense ?? summary.expense - variable;
    const reserved = summary.reserved ?? 0;
    const available = availableOf(summary);
    const free = available - reserved;

    const shownAvailable = Math.max(available, 0);
    const shownReserved = Math.min(Math.max(reserved, 0), shownAvailable);
    const amounts: Array<[DistributionSegmentKey, number, number]> = [
        ['fixed', fixed, Math.max(fixed, 0)],
        ['variable', variable, Math.max(variable, 0)],
        ['saved', summary.savings, Math.max(summary.savings, 0)],
        ['invested', summary.investments, Math.max(summary.investments, 0)],
        ['reserved', reserved, shownReserved],
        ['free', free, shownAvailable - shownReserved],
    ];

    const scale = amounts.reduce((total, [, , shown]) => total + shown, 0);
    const segments = amounts.map(([key, amount, shown]) => ({
        key,
        amount,
        width: widthOf(shown, scale),
    }));
    const availableWidth = widthOf(shownAvailable, scale);

    return {
        income: summary.income,
        allocated: allocatedOf(summary),
        available,
        reserved,
        free,
        segments,
        allocatedWidth: scale > 0 ? 100 - availableWidth : 0,
        availableWidth,
    };
}
