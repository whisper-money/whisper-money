import { type ProjectedMonth } from '@/lib/forecast/types';
import {
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ForecastPage from './index';

vi.mock('@inertiajs/react', async () => ({
    Head: () => null,
    Link: (await import('@/lib/inertia-link.fixture')).FixtureLink,
    usePage: () => ({ props: { pricing: { defaultPlan: 'monthly' } } }),
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/subscription/upgrade-dialog', () => ({
    UpgradeDialog: ({ open }: { open: boolean }) =>
        open ? <div data-test="upgrade-dialog" /> : null,
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number) => `€${amount / 100}`,
        formatAmountNumber: (amount: number) => String(amount / 100),
    }),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

const MONTHS = [
    '2026-11',
    '2026-12',
    '2027-01',
    '2027-02',
    '2027-03',
    '2027-04',
];

function projected(month: string, min: number): ProjectedMonth {
    return {
        month,
        min,
        minDate: `${month}-10`,
        close: min + 100000,
        income: 300000,
        expenses: 200000,
        transfers: 0,
        growth: 0,
        estimated: 20000,
        band: 8000,
        pessimisticMin: min - 8000,
    };
}

function line(overrides: Partial<ForecastLine>): ForecastLine {
    return {
        id: 'rent',
        name: 'Rent',
        kind: 'monthly',
        source: 'detected',
        anchorDate: '2026-01-05',
        direction: null,
        counterpartyName: null,
        amounts: {},
        current: { planned: -190000, real: -190000, status: 'matched' },
        ...overrides,
    };
}

const home: ForecastGroup = {
    key: 'home',
    categoryId: 'home',
    name: 'Home',
    icon: null,
    color: null,
    items: [
        line({
            amounts: Object.fromEntries(MONTHS.map((m) => [m, -190000])),
        }),
        line({
            id: 'insurance',
            name: 'Car insurance',
            kind: 'yearly',
            source: 'manual',
            anchorDate: '2026-04-10',
            amounts: { '2027-04': -700000 },
            current: { planned: null, real: null, status: 'none' },
        }),
    ],
    estimate: {
        basis: 'median',
        budgetAmount: null,
        amounts: Object.fromEntries(MONTHS.slice(1).map((m) => [m, -6000])),
        current: { spent: 2000, planned: 6000, left: 4000 },
    },
};

function forecast(overrides: Partial<ForecastData> = {}): ForecastData {
    return {
        today: '2026-11-26',
        horizon: 6,
        isPro: true,
        currencyCode: 'EUR',
        account: {
            id: 'checking',
            name: 'Checking',
            type: 'checking',
            currencyCode: 'EUR',
            minimumBalance: null,
        },
        accounts: [
            { id: 'checking', name: 'Checking', hasAlert: true },
            { id: 'savings', name: 'Savings', hasAlert: false },
        ],
        months: [
            ...MONTHS.map((month, index) => ({
                month,
                status:
                    index === 0 ? ('current' as const) : ('future' as const),
            })),
            { month: '2027-05', status: 'future' as const },
        ],
        projection: [...MONTHS, '2027-05'].map((month) =>
            projected(month, month === '2027-04' ? -50000 : 110000),
        ),
        past: [
            {
                month: '2026-09',
                income: 300000,
                expenses: 190000,
                close: 90000,
                min: 80000,
            },
            {
                month: '2026-10',
                income: 300000,
                expenses: 190000,
                close: 100000,
                min: 90000,
            },
            {
                month: '2026-11',
                income: 300000,
                expenses: 192000,
                close: 100000,
                min: 95000,
            },
        ],
        alert: {
            level: 'strong',
            month: '2027-04',
            min: -50000,
            minDate: '2027-04-10',
            softMonths: [],
            causes: [{ name: 'Car insurance', amount: 700000 }],
        },
        lockedAlertCount: null,
        kpis: {
            balanceToday: 100000,
            end: { month: '2027-05', close: 210000, band: 8000 },
            lowest: { month: '2027-04', min: -50000, minDate: '2027-04-10' },
            concreteShare: 90,
        },
        plan: { income: [], expenses: [home], transfers: [] },
        ...overrides,
    };
}

function renderPage(data: ForecastData) {
    return render(<ForecastPage forecast={data} projectionInput={null} />);
}

function selectedColumn(): string | null {
    return (
        document
            .querySelector('[data-test^="forecast-column-"][data-selected]')
            ?.getAttribute('data-test')
            ?.replace('forecast-column-', '') ?? null
    );
}

describe('the forecast page', () => {
    it('opens the group that explains the problem and folds the rest on demand', () => {
        renderPage(forecast());

        expect(
            screen.getByText('Car insurance', { selector: 'span' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Home/ }));
        expect(screen.queryByText('Estimated rest')).not.toBeInTheDocument();

        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );
        expect(screen.getByText('Estimated rest')).toBeInTheDocument();
        expect(screen.getByText('Collapse all')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Collapse all'));
        expect(screen.queryByText('Estimated rest')).not.toBeInTheDocument();
        expect(screen.getByText('Expand all')).toBeInTheDocument();
    });

    it('selects the problem month first and follows the month picked on the chart', () => {
        renderPage(forecast());

        expect(selectedColumn()).toBe('2027-04');

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-chart-month-2027-01"]',
            )!,
        );
        expect(selectedColumn()).toBe('2027-01');

        const alert = document.querySelector<HTMLElement>(
            '[data-test="forecast-alert-strong"]',
        )!;
        fireEvent.click(
            within(alert).getByRole('button', {
                name: 'See April in the plan',
            }),
        );
        expect(selectedColumn()).toBe('2027-04');
    });

    it('cannot pick a past month', () => {
        renderPage(forecast());

        const past = document.querySelector<HTMLButtonElement>(
            '[data-test="forecast-chart-month-2026-09"]',
        )!;

        expect(past).toBeDisabled();
    });

    it('shows the current month against the plan', () => {
        renderPage(forecast());

        // Home is already open: its yearly insurance explains April.
        expect(screen.getByText('✓ as planned')).toBeInTheDocument();
        expect(screen.getByText('of 60 · 40 left')).toBeInTheDocument();
    });

    it('marks the account with a problem and says it in a strong alert', () => {
        renderPage(forecast());

        const tabs = screen.getByRole('navigation', { name: 'Account' });
        expect(
            within(tabs).getByRole('img', { name: 'has a warning' }),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="forecast-alert-strong"]'),
        ).toHaveTextContent('You will drop to €-500 around 10 April');
        expect(
            document.querySelector('[data-test="forecast-free-banner"]'),
        ).toBeNull();
    });

    it('says the account holds up when nothing is wrong', () => {
        renderPage(
            forecast({
                alert: {
                    level: 'ok',
                    month: null,
                    min: null,
                    minDate: null,
                    softMonths: [],
                    causes: [],
                },
            }),
        );

        expect(
            document.querySelector('[data-test="forecast-alert-ok"]'),
        ).toHaveTextContent('Your Checking holds up for the next 6 months');
    });
});

describe('the forecast page without Pro', () => {
    const locked = forecast({
        isPro: false,
        lockedAlertCount: 1,
        months: [
            { month: '2026-11', status: 'current' },
            { month: '2026-12', status: 'future' },
            ...MONTHS.slice(2).map((month) => ({
                month,
                status: 'locked' as const,
            })),
        ],
        projection: MONTHS.slice(0, 2).map((month) => projected(month, 110000)),
        alert: {
            level: 'ok',
            month: null,
            min: null,
            minDate: null,
            softMonths: [],
            causes: [],
        },
        plan: {
            income: [],
            expenses: [
                {
                    ...home,
                    items: [
                        line({
                            amounts: { '2026-11': -190000, '2026-12': -190000 },
                        }),
                    ],
                    estimate: {
                        ...home.estimate!,
                        amounts: { '2026-12': -6000 },
                    },
                },
            ],
            transfers: [],
        },
    });

    it('blurs the locked months, tags them PRO and offers the upgrade', () => {
        renderPage(locked);
        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );

        const columns = MONTHS.slice(2).map((month) =>
            document.querySelector(`[data-test="forecast-column-${month}"]`),
        );
        columns.forEach((column) => expect(column).toHaveTextContent('PRO'));
        expect(
            document.querySelectorAll('[data-test="forecast-locked-cell"]')
                .length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText('Pro').length).toBe(4);
        expect(
            document.querySelector('[data-test="forecast-alert-ok"]'),
        ).toBeNull();

        const banner = document.querySelector(
            '[data-test="forecast-free-banner"]',
        )!;
        expect(banner).toHaveTextContent('You have 1 warning further ahead');

        fireEvent.click(
            within(banner as HTMLElement).getByRole('button', {
                name: 'Unlock 12 months with Pro',
            }),
        );
        expect(
            document.querySelector('[data-test="upgrade-dialog"]'),
        ).not.toBeNull();
    });

    it('does not let a locked month be picked', () => {
        renderPage(locked);

        expect(
            document.querySelector(
                '[data-test="forecast-chart-month-2027-03"]',
            ),
        ).toBeDisabled();
    });
});
