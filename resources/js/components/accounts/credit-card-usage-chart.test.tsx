import type { CreditCardDetail, CreditCardUsage } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import {
    CreditCardUsageChart,
    usageChartData,
} from './credit-card-usage-chart';

vi.mock('@inertiajs/react', () => ({
    router: { patch: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

vi.mock('@/actions/App/Http/Controllers/CreditCardDetailController', () => ({
    update: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
    destroy: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

// Recharts measures its container, which jsdom never lays out.
vi.mock('@/components/ui/chart', () => ({
    ChartContainer: ({ children }: { children: ReactNode }) => (
        <div data-testid="usage-chart">{children}</div>
    ),
    ChartTooltip: () => null,
    ChartTooltipContent: () => null,
}));

vi.mock('recharts', () => ({
    AreaChart: () => null,
    Area: () => null,
    ReferenceLine: () => null,
    XAxis: () => null,
    YAxis: () => null,
}));

const withDates: CreditCardDetail = {
    statement_closing_date: '2026-10-05',
    payment_due_date: '2026-10-20',
    credit_limit: 100000,
};

function usage(overrides: Partial<CreditCardUsage> = {}): CreditCardUsage {
    return {
        limit: 100000,
        used: 25000,
        available: 75000,
        period_from: '2026-10-01',
        period_to: '2026-10-31',
        daily: [
            { date: '2026-10-01', used: 10000 },
            { date: '2026-10-02', used: 25000 },
        ],
        ...overrides,
    };
}

function renderChart(
    cardUsage: CreditCardUsage,
    detail: CreditCardDetail | null = null,
) {
    return render(
        <CreditCardUsageChart
            accountId="card-1"
            currencyCode="EUR"
            usage={cardUsage}
            detail={detail}
        />,
    );
}

describe('usageChartData', () => {
    it('spans the whole window and stops the line after the last known day', () => {
        const points = usageChartData(usage());

        expect(points).toHaveLength(31);
        expect(points[0]).toEqual({ date: '2026-10-01', used: 10000 });
        expect(points[1]).toEqual({ date: '2026-10-02', used: 25000 });
        expect(points[2]).toEqual({ date: '2026-10-03', used: null });
        expect(points.at(-1)).toEqual({ date: '2026-10-31', used: null });
    });
});

describe('CreditCardUsageChart', () => {
    it('reads out the limit, what is used and what is left', () => {
        renderChart(usage());

        expect(screen.getByText('Credit limit')).toBeInTheDocument();
        expect(screen.getByText('Used')).toBeInTheDocument();
        expect(screen.getByText('Available')).toBeInTheDocument();
        expect(screen.getByText('Limit')).toBeInTheDocument();
        expect(screen.getByText('€250.00')).toBeInTheDocument();
        expect(screen.getByText('€750.00')).toBeInTheDocument();
        expect(screen.getByText('€1,000.00')).toBeInTheDocument();
        expect(screen.getByText('25% of the limit used')).toBeInTheDocument();
        expect(screen.getByTestId('usage-chart')).toBeInTheDocument();
        expect(screen.queryByText('Over limit')).not.toBeInTheDocument();
    });

    it('names the calendar month when the card has no statement dates', () => {
        renderChart(usage());

        expect(screen.getByText('October 2026')).toBeInTheDocument();
    });

    it('names the statement dates when the card has them', () => {
        renderChart(
            usage({ period_from: '2026-09-06', period_to: '2026-11-05' }),
            withDates,
        );

        expect(
            screen.getByText('From Sep 6, 2026 to Nov 5, 2026'),
        ).toBeInTheDocument();
    });

    it('flags a card used past its limit', () => {
        renderChart(usage({ used: 120000, available: -20000 }));

        expect(screen.getByText('Over limit')).toBeInTheDocument();
        expect(screen.getByText('-€200.00')).toBeInTheDocument();
        expect(screen.getByText('Over the limit by')).toBeInTheDocument();
        expect(screen.getByText('€200.00')).toBeInTheDocument();
        expect(screen.getByText('120% of the limit used')).toBeInTheDocument();
    });

    it('says so instead of drawing an empty chart when there is no daily data', () => {
        renderChart(usage({ used: 0, available: 100000, daily: [] }));

        expect(
            screen.getByText('No spending recorded in this period yet'),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('usage-chart')).not.toBeInTheDocument();
    });

    it('shows what is used and asks for the limit when there is none', () => {
        renderChart(usage({ limit: null, available: null }));

        expect(screen.getByText('Credit used')).toBeInTheDocument();
        expect(screen.getByText('€250.00')).toBeInTheDocument();
        expect(screen.queryByText('Available')).not.toBeInTheDocument();
        expect(screen.queryByTestId('usage-chart')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Set credit limit' }),
        );

        expect(
            screen.getByRole('dialog', { name: 'Card details' }),
        ).toBeInTheDocument();
    });

    it('never reads a net refund as a negative share of the limit', () => {
        renderChart(usage({ used: -5000, available: 105000 }));

        expect(screen.getByText('0% of the limit used')).toBeInTheDocument();
    });

    it('explains that payments to the card are not subtracted', () => {
        renderChart(usage());

        expect(
            screen.getByText(/Payments to the card are not subtracted\./),
        ).toBeInTheDocument();
    });
});
