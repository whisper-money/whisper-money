import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CashflowPage from './index';

const pageProps = vi.hoisted(() => ({
    current: { features: { cashflowAvailableFree: false } },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
    usePage: () => ({
        props: {
            auth: { user: { currency_code: 'EUR' } },
            period: '2026-10',
            periodType: 'month',
            ...pageProps.current,
        },
    }),
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/charts', () => ({
    CashflowTrendChart: () => null,
    SankeyChart: () => null,
}));

vi.mock('@/components/cashflow/monthly-savings-cashflow-card', () => ({
    MonthlySavingsCashflowCard: () => null,
}));

vi.mock('@/components/cashflow/breakdown-card', () => ({
    BreakdownCard: () => null,
}));

vi.mock('@/components/cashflow/period-navigation', () => ({
    PeriodNavigation: () => null,
}));

vi.mock('@/hooks/use-period-url-sync', () => ({
    usePeriodUrlSync: () => undefined,
}));

vi.mock('@/hooks/use-cashflow-data', () => {
    const current = {
        income: 200000,
        expense: 132000,
        net: 68000,
        savings_rate: 0,
        savings: 25000,
        investments: 25000,
        fixed_expense: 94000,
        variable_expense: 38000,
        reserved: 0,
        budgets: [],
    };

    return {
        useCashflowData: () => ({
            summary: { current, previous: current },
            sankey: {},
            trend: [],
            incomeBreakdown: {},
            expenseBreakdown: {},
            isLoading: false,
            hasError: false,
            refetch: () => undefined,
        }),
    };
});

describe('CashflowPage', () => {
    beforeEach(() => {
        pageProps.current = { features: { cashflowAvailableFree: false } };
    });

    it('leaves the income distribution out while the flag is off', () => {
        render(<CashflowPage />);

        expect(screen.queryByText('Income distribution')).toBeNull();
        expect(screen.queryByText('Free (cashflow)')).toBeNull();
    });

    it('shows the income distribution and the free highlight with the flag on', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);

        expect(screen.getByText('Income distribution')).toBeTruthy();
        expect(screen.getAllByText('Free (cashflow)').length).toBeGreaterThan(
            0,
        );
    });
});
