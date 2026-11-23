import { CategoryColor, getCategoryChartColor } from '@/types/category';

/**
 * The color of each chart role. They are CSS variables whose value per chart
 * theme lives in `resources/css/app.css` (the chart role tokens), so a chart
 * draws the same way in every theme and never reads the theme itself.
 */
export const chartRoleColors = {
    /** Main balance line of an account. */
    accountMainLineColor: 'var(--chart-account-line)',
    /** Invested / gain line of an account. */
    accountGainLineColor: 'var(--chart-account-gain)',
    /** Mortgage owed line of a real estate account. */
    mortgageLineColor: 'var(--chart-mortgage)',
    /** Equity line of a real estate account. */
    equityLineColor: 'var(--chart-equity)',
    /** Income series (cashflow trend, sankey links). */
    cashflowIncomeColor: 'var(--chart-income)',
    /** Expense series (cashflow trend, sankey links). */
    cashflowExpenseColor: 'var(--chart-expense)',
    /** Liability marker in net worth tooltips. */
    liabilityDotColor: 'var(--chart-liability)',
} as const;

const CATEGORY_CHART_SLOTS = 8;

/**
 * The color of the category series at `index`: the category's own color in
 * the colorful theme, the `--chart-N` slot for its index in the others. The
 * theme picks between the two through `--chart-category-mix` (100% or 0%), so
 * the returned value is the same in every theme.
 */
export function categoryChartColor(
    color: CategoryColor,
    index: number,
): string {
    const slot = (index % CATEGORY_CHART_SLOTS) + 1;

    return `color-mix(in oklch, ${getCategoryChartColor(color)} var(--chart-category-mix), var(--chart-${slot}))`;
}
