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
});
