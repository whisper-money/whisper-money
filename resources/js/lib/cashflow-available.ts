import { type CashflowSummary } from '@/hooks/use-cashflow-data';

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
