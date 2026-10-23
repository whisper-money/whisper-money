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

/** Whether the period had any money moving, so comparing against it means something. */
export function hasPeriodData(summary: CashflowSummary): boolean {
    return summary.income > 0 || summary.expense > 0;
}

export interface ExpenseSplit {
    fixed: number;
    variable: number;
    general: number;
}

/**
 * The expense split by the expense type of the budgets tracking it; general
 * is what no budget tracks, so the three add up to the expense.
 */
export function expenseSplitOf(summary: CashflowSummary): ExpenseSplit {
    const fixed = summary.fixed_expense ?? 0;
    const variable = summary.variable_expense ?? 0;

    return {
        fixed,
        variable,
        general: summary.general_expense ?? summary.expense - fixed - variable,
    };
}
