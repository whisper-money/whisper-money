import { ChartColorScheme, SharedData } from '@/types';
import { CategoryColor, getCategoryChartColor } from '@/types/category';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'chart-color-scheme';

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyColorScheme = (scheme: ChartColorScheme) => {
    if (typeof document === 'undefined') {
        return;
    }

    if (scheme === 'neutral') {
        document.documentElement.removeAttribute('data-chart-color');
    } else {
        document.documentElement.setAttribute('data-chart-color', scheme);
    }
};

export function initializeChartColorScheme() {
    if (typeof window === 'undefined') {
        return;
    }

    const saved =
        (localStorage.getItem(STORAGE_KEY) as ChartColorScheme) || 'colorful';
    applyColorScheme(saved);
}

export function useChartColorScheme() {
    const { chartColorScheme: serverScheme } = usePage<SharedData>().props;
    const [scheme, setScheme] = useState<ChartColorScheme>('colorful');

    const updateScheme = useCallback((newScheme: ChartColorScheme) => {
        setScheme(newScheme);
        localStorage.setItem(STORAGE_KEY, newScheme);
        setCookie(STORAGE_KEY, newScheme);
        applyColorScheme(newScheme);
    }, []);

    useEffect(() => {
        const saved = localStorage.getItem(
            STORAGE_KEY,
        ) as ChartColorScheme | null;
        updateScheme(saved || serverScheme || 'colorful');
    }, [serverScheme, updateScheme]);

    return { scheme, updateScheme } as const;
}

/**
 * Returns color helpers derived from the active chart color scheme.
 *
 * - `accountMainLineColor`  – stroke for the main balance line in account cards
 * - `accountGainLineColor`  – stroke for the invested/gain line in account cards
 * - `mortgageLineColor`     – stroke for the mortgage owed line in real estate charts
 * - `equityLineColor`       – stroke for the equity line in real estate charts
 * - `categoryBarColor`      – progress bar color for a given category (colorful
 *                             uses the category's own color; other schemes cycle
 *                             through --chart-* variables by index)
 */
export function useChartColors() {
    const { chartColorScheme } = usePage<SharedData>().props;
    const isColorful = chartColorScheme === 'colorful';

    const accountMainLineColor = isColorful
        ? 'var(--account-line)'
        : 'var(--color-chart-2)';

    const accountGainLineColor = isColorful
        ? 'var(--color-emerald-500)'
        : 'var(--color-chart-6)';

    const mortgageLineColor = isColorful
        ? 'var(--color-amber-500)'
        : 'var(--color-chart-5)';

    const equityLineColor = isColorful
        ? 'var(--color-emerald-500)'
        : 'var(--color-chart-4)';

    const cashflowIncomeColor = isColorful
        ? 'var(--cashflow-income)'
        : 'var(--color-chart-2)';

    const cashflowExpenseColor = isColorful
        ? 'var(--cashflow-expense)'
        : 'var(--color-chart-5)';

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

    const categoryBarColor = (color: CategoryColor, index: number): string =>
        isColorful
            ? getCategoryChartColor(color)
            : CHART_COLORS[index % CHART_COLORS.length];

    return {
        accountMainLineColor,
        accountGainLineColor,
        mortgageLineColor,
        equityLineColor,
        cashflowIncomeColor,
        cashflowExpenseColor,
        categoryBarColor,
    };
}
