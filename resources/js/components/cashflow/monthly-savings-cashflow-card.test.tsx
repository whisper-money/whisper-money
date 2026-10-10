import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MonthlySavingsCashflowCard } from './monthly-savings-cashflow-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock(
    '@/actions/App/Http/Controllers/Api/CashflowAnalyticsController',
    () => ({
        monthlySavings: {
            url: ({ query }: { query: { month: string } }) =>
                `/api/cashflow/monthly-savings?month=${query.month}`,
        },
    }),
);

function respondWith(data: unknown, ok = true) {
    const fetchMock = vi.fn().mockResolvedValue({
        ok,
        status: ok ? 200 : 500,
        json: async () => ({ data }),
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('MonthlySavingsCashflowCard', () => {
    it('shows the month against the plan and its share of net cashflow', async () => {
        const fetchMock = respondWith({
            month: '2026-09',
            saved: 47000,
            target: 50000,
            difference: -3000,
            met: 1,
            total: 2,
            status: 'missed',
            history: [
                {
                    month: '2026-08',
                    saved: 30000,
                    target: 50000,
                    status: 'missed',
                },
                {
                    month: '2026-09',
                    saved: 47000,
                    target: 50000,
                    status: 'missed',
                },
            ],
        });

        render(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 8, 15)}
                net={94000}
                currencyCode="EUR"
            />,
        );

        expect(
            await screen.findByText(
                "50% of the month's net cashflow went to monthly goals",
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('1 of 2 goals met')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/cashflow/monthly-savings?month=2026-09',
        );
    });

    it('says what is left in the month still running and hides a share over 100%', async () => {
        respondWith({
            month: '2026-10',
            saved: 20000,
            target: 50000,
            difference: -30000,
            met: 1,
            total: 2,
            status: 'in_progress',
            history: [
                {
                    month: '2026-10',
                    saved: 20000,
                    target: 50000,
                    status: 'in_progress',
                },
            ],
        });

        render(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 9, 3)}
                net={10000}
                currencyCode="EUR"
            />,
        );

        expect(
            await screen.findByText('1 of 2 goals reached so far'),
        ).toBeInTheDocument();
        expect(screen.getByText(/to go/)).toBeInTheDocument();
        expect(screen.queryByText(/against the plan/)).toBeNull();
        expect(screen.queryByText(/net cashflow/)).toBeNull();
    });

    it('stays out of the page when no goal had the month or the load fails', async () => {
        const fetchMock = respondWith(null);
        const { container, rerender } = render(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 5, 1)}
                net={null}
                currencyCode="EUR"
            />,
        );

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(container).toBeEmptyDOMElement();

        respondWith(null, false);
        rerender(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 6, 1)}
                net={null}
                currencyCode="EUR"
            />,
        );

        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });

    it('keeps the history for a month no goal was judged in', async () => {
        respondWith({
            month: '2026-12',
            saved: 0,
            target: 0,
            difference: 0,
            met: 0,
            total: 0,
            target_pending: false,
            status: null,
            history: [
                {
                    month: '2026-08',
                    saved: 30000,
                    target: 50000,
                    status: 'missed',
                },
            ],
        });

        render(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 11, 15)}
                net={null}
                currencyCode="EUR"
            />,
        );

        expect(
            await screen.findByText('No monthly goal counts in this month.'),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('img')).toHaveLength(1);
    });

    it('says the target is still to come instead of met', async () => {
        respondWith({
            month: '2026-10',
            saved: 0,
            target: 0,
            difference: 0,
            met: 0,
            total: 1,
            target_pending: true,
            status: 'in_progress',
            history: [
                {
                    month: '2026-10',
                    saved: 0,
                    target: 0,
                    status: 'in_progress',
                },
            ],
        });

        render(
            <MonthlySavingsCashflowCard
                month={new Date(2026, 9, 15)}
                net={null}
                currencyCode="EUR"
            />,
        );

        expect(
            await screen.findByText(/the target grows as income comes in/),
        ).toBeInTheDocument();
        expect(screen.queryByText('Target met')).toBeNull();
    });
});
