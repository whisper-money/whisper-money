import { CASHFLOW_BLOCKS } from '@/lib/cashflow-block';
import { type CashflowBlock } from '@/types/transaction';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CashflowPage from './index';

type PageProps = {
    features: { cashflowAvailableFree: boolean };
    period?: string;
    periodType?: 'month' | 'quarter' | 'year';
};

const pageProps = vi.hoisted(() => ({
    current: { features: { cashflowAvailableFree: false } } as PageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        onClick,
        ...props
    }: {
        children: ReactNode;
        href: string;
        onClick?: () => void;
    }) => (
        <a
            href={href}
            onClick={(event) => {
                event.preventDefault();
                onClick?.();
            }}
            {...props}
        >
            {children}
        </a>
    ),
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
    formatPeriodLabel: () => 'October 2026',
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
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

const BLOCK_LABELS: Record<CashflowBlock, string> = {
    fixed: 'Fixed',
    variable: 'Variable',
    general: 'General',
    saved: 'Saved (cashflow)',
    invested: 'Invested',
};

function expectBlockLinks(
    blocks: readonly CashflowBlock[],
    from: string,
    to: string,
) {
    for (const block of blocks) {
        const link = screen.getByRole('link', {
            name: `View ${BLOCK_LABELS[block]} transactions`,
        });
        const url = new URL(
            link.getAttribute('href') ?? '',
            'http://localhost',
        );

        expect(url.pathname).toBe('/transactions');
        expect(url.searchParams.get('cashflow_block')).toBe(block);
        expect(url.searchParams.get('date_from')).toBe(from);
        expect(url.searchParams.get('date_to')).toBe(to);
    }
}

describe('CashflowPage', () => {
    beforeEach(() => {
        pageProps.current = { features: { cashflowAvailableFree: false } };
    });

    it('leaves the free highlight out while the flag is off', () => {
        render(<CashflowPage />);

        expect(screen.queryByText('Income distribution')).toBeNull();
        expect(screen.queryByText('Free (cashflow)')).toBeNull();
        expect(screen.queryByText('Expenses (cashflow)')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'View details' }),
        ).toBeNull();
    });

    it('shows the free highlight but no income distribution with the flag on', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);

        expect(screen.getAllByText('Free (cashflow)').length).toBeGreaterThan(
            0,
        );
        expect(screen.queryByText('Income distribution')).toBeNull();
    });

    it('shows the expenses card and opens its details with the flag on', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);

        expect(screen.getByText('Expenses (cashflow)')).toBeTruthy();
        expect(
            screen.queryByText('Where your money goes · October 2026'),
        ).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'View details' }));

        expect(
            screen.getByText('Where your money goes · October 2026'),
        ).toBeTruthy();
    });

    it.each([
        ['month', '2026-10', '2026-10-01', '2026-10-31'],
        ['quarter', '2026-Q3', '2026-07-01', '2026-09-30'],
        ['year', '2026', '2026-01-01', '2026-12-31'],
    ] as const)(
        'links every block to its transactions over the %s in view',
        (periodType, period, from, to) => {
            pageProps.current = {
                features: { cashflowAvailableFree: true },
                period,
                periodType,
            };

            render(<CashflowPage />);
            expectBlockLinks(['fixed', 'variable', 'general'], from, to);

            // The drawer hides the page behind it from the accessibility tree.
            fireEvent.click(
                screen.getByRole('button', { name: 'View details' }),
            );
            expectBlockLinks(CASHFLOW_BLOCKS, from, to);
        },
    );

    it('closes the drawer when one of its links is followed', async () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);
        fireEvent.click(screen.getByRole('button', { name: 'View details' }));
        fireEvent.click(
            screen.getByRole('link', {
                name: 'View Saved (cashflow) transactions',
            }),
        );

        // vaul keeps the content mounted through its exit animation.
        await waitFor(() =>
            expect(
                screen
                    .getByText('Where your money goes · October 2026')
                    .closest('[data-slot="drawer-content"]')
                    ?.getAttribute('data-state'),
            ).toBe('closed'),
        );
    });
});
