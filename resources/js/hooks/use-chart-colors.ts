import { SharedData } from '@/types';
import { CategoryColor, getCategoryChartColor } from '@/types/category';
import { usePage } from '@inertiajs/react';

interface ChartColors {
    accountMainLineColor: string;
    accountGainLineColor: string;
    cashflowIncomeColor: string;
    cashflowExpenseColor: string;
    categoryBarColor: (color: CategoryColor, index: number) => string;
}

const CHART_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
    'var(--chart-7)',
    'var(--chart-8)',
];

export function useChartColors(): ChartColors {
    const { chartColorScheme } = usePage<SharedData>().props;
    const isColorful = chartColorScheme === 'colorful';

    return {
        accountMainLineColor: isColorful
            ? 'var(--account-line)'
            : 'var(--color-chart-2)',
        accountGainLineColor: isColorful
            ? 'var(--color-emerald-500)'
            : 'var(--color-chart-6)',
        cashflowIncomeColor: isColorful
            ? 'var(--cashflow-income)'
            : 'var(--color-chart-2)',
        cashflowExpenseColor: isColorful
            ? 'var(--cashflow-expense)'
            : 'var(--color-chart-5)',
        categoryBarColor: (color: CategoryColor, index: number): string =>
            isColorful
                ? getCategoryChartColor(color)
                : CHART_COLORS[index % CHART_COLORS.length],
    };
}
