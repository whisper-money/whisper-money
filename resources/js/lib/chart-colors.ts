import { CategoryColor, getCategoryChartColor } from '@/types/category';

/**
 * The chart role tokens: CSS variables whose value per chart theme lives in
 * `resources/css/app.css`, so a chart draws the same way in every theme and
 * never reads the theme itself. See docs/design-system.md (Chart role tokens).
 */
export const chartRoleTokens = {
    /** Main balance line of an account. */
    accountMainLineColor: '--chart-account-line',
    /** Invested / gain line of an account. */
    accountGainLineColor: '--chart-account-gain',
    /** Mortgage owed line of a real estate account. */
    mortgageLineColor: '--chart-mortgage',
    /** Equity line of a real estate account. */
    equityLineColor: '--chart-equity',
    /** Income series (cashflow trend, sankey links). */
    cashflowIncomeColor: '--chart-income',
    /** Expense series (cashflow trend, sankey links). */
    cashflowExpenseColor: '--chart-expense',
    /** Liability marker in net worth tooltips. */
    liabilityDotColor: '--chart-liability',
} as const;

type ChartRole = keyof typeof chartRoleTokens;

/** Each chart role as a CSS color, e.g. `var(--chart-income)`. */
export const chartRoleColors = Object.fromEntries(
    Object.entries(chartRoleTokens).map(([role, token]) => [
        role,
        `var(${token})`,
    ]),
) as Record<ChartRole, string>;

/**
 * Category series cycle `--chart-1..8`: slots 9 and 10 are left out on
 * purpose, as they always were, so the colors don't shift.
 */
const CATEGORY_CHART_SLOTS = 8;

/**
 * The color of the category series at `index`: the category's own color in
 * the colorful theme, the `--chart-N` slot for its index in the others. The
 * theme picks between the two through `--chart-category-mix` (100% or 0%), so
 * the returned value is the same in every theme. It is an opaque CSS color:
 * pass it to `fill`, `stroke` or a style, don't append an alpha to it.
 */
export function categoryChartColor(
    color: CategoryColor,
    index: number,
): string {
    const slot = (index % CATEGORY_CHART_SLOTS) + 1;

    return `color-mix(in oklch, ${getCategoryChartColor(color)} var(--chart-category-mix, 0%), var(--chart-${slot}))`;
}
