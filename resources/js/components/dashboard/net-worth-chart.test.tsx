import { type NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { NetWorthChart } from './net-worth-chart';

vi.mock(
    '@/actions/App/Http/Controllers/Settings/NetWorthChartLoanPreferenceController',
    () => ({ update: { url: () => '/settings/loans' } }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Settings/NetWorthChartRealEstatePreferenceController',
    () => ({ update: { url: () => '/settings/real-estate' } }),
);

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    router: { patch: vi.fn() },
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/components/privacy-reveal-button', () => ({
    PrivacyRevealButton: () => null,
}));

vi.mock('@/components/charts', () => ({
    ChartGranularityToggle: () => null,
    ChartSettingsPopover: () => null,
    ChartViewToggle: () => null,
    MoMChart: () => null,
    MoMPercentChart: () => null,
}));

vi.mock('@/components/ui/stacked-bar-chart', () => ({
    StackedBarChart: ({ data }: { data: Array<{ month: string }> }) => (
        <ol data-testid="bars">
            {data.map((point) => (
                <li key={point.month}>{point.month}</li>
            ))}
        </ol>
    ),
}));

vi.mock('@/components/ui/stacked-area-chart', () => ({
    StackedAreaChart: () => null,
}));

const MONTHS = [
    '2025-11',
    '2025-12',
    '2026-01',
    '2026-02',
    '2026-03',
    '2026-04',
    '2026-05',
    '2026-06',
    '2026-07',
    '2026-08',
    '2026-09',
    '2026-10',
    '2026-11',
];

function evolution(values: Array<number | null>): NetWorthEvolutionData {
    return {
        data: MONTHS.map((month, index) => ({
            month,
            timestamp: index,
            checking: values[index],
        })) as NetWorthEvolutionData['data'],
        accounts: {
            checking: {
                id: 'checking',
                name: 'Checking',
                type: 'checking',
                currency_code: 'EUR',
                bank: { id: 'b', name: 'Bank', logo: null },
                banking_connection_id: null,
            },
        } as unknown as NetWorthEvolutionData['accounts'],
        currency_code: 'EUR',
    };
}

describe('NetWorthChart', () => {
    it('draws no bars before the first month with data and says why', () => {
        render(
            <NetWorthChart
                data={evolution([
                    ...Array(7).fill(null),
                    200000,
                    220000,
                    220000,
                    230000,
                    240000,
                    250000,
                ])}
            />,
        );

        expect(
            screen.getAllByRole('listitem').map((item) => item.textContent),
        ).toEqual([
            '2026-06',
            '2026-07',
            '2026-08',
            '2026-09',
            '2026-10',
            '2026-11',
        ]);
        expect(screen.getByTestId('net-worth-no-data').textContent).toContain(
            'Dec 2025 – May: before your first balance or movement',
        );
    });

    it('measures the long trend from the first month with data', () => {
        render(
            <NetWorthChart
                data={evolution([
                    ...Array(7).fill(null),
                    200000,
                    220000,
                    220000,
                    230000,
                    240000,
                    250000,
                ])}
            />,
        );

        expect(screen.getByText('+25.0%')).toBeTruthy();
        expect(screen.getByText('since Jun 2026')).toBeTruthy();
        expect(screen.queryByText('for the last 12 months')).toBeNull();
    });

    it('keeps the 12-month range when the history covers it', () => {
        render(<NetWorthChart data={evolution(Array(13).fill(100000))} />);

        expect(screen.getAllByRole('listitem')).toHaveLength(12);
        expect(screen.queryByTestId('net-worth-no-data')).toBeNull();
        expect(screen.getByText('for the last 12 months')).toBeTruthy();
    });

    it('says how one reading becomes a line, before the user has any movement', () => {
        const oneReading = evolution([...Array(12).fill(null), 800000]);
        const { unmount } = render(
            <NetWorthChart data={oneReading} isFirstReading />,
        );

        expect(
            screen.getByText(
                'First reading, November. Update your balances once a month and this becomes a line.',
            ),
        ).toBeTruthy();
        unmount();

        render(<NetWorthChart data={oneReading} />);

        expect(screen.queryByText(/First reading/)).toBeNull();
    });
});
