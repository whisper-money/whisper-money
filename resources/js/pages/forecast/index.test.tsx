import { accountMonths, withLine } from '@/lib/forecast/edits';
import {
    type ProjectedMonth,
    type ProjectionInput,
} from '@/lib/forecast/types';
import {
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ForecastPage from './index';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({
    Head: () => null,
    Link: (await import('@/lib/inertia-link.fixture')).FixtureLink,
    router: { visit },
    usePage: () => ({
        props: {
            pricing: { defaultPlan: 'monthly' },
            categories: [
                {
                    id: 'home',
                    name: 'Home',
                    icon: 'House',
                    color: 'blue',
                    type: 'expense',
                    cashflow_direction: 'out',
                    parent_id: null,
                },
            ],
        },
    }),
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
        current: {
            planned: -190000,
            real: -190000,
            date: '2026-11-05',
            status: 'matched',
        },
        accountId: 'checking',
        categoryId: 'home',
        definition: {
            id: overrides.id ?? 'rent',
            accountId: 'checking',
            destinationAccountId: null,
            frequency: 'monthly',
            anchorDate: '2026-01-05',
            endsOn: null,
            versions: [{ effectiveFrom: '2026-01-05', amount: -190000 }],
            overrides: [],
        },
        occurrenceDates: {},
        matchText: null,
        amountTolerance: 0,
        dateWindowDays: 3,
        payments: [],
        drift: null,
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
            current: { planned: null, real: null, date: null, status: 'none' },
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
            annualRateBps: null,
            alertThreshold: 0,
        },
        accounts: [
            {
                id: 'checking',
                name: 'Checking',
                type: 'checking',
                currencyCode: 'EUR',
                hasAlert: true,
            },
            {
                id: 'savings',
                name: 'Savings',
                type: 'savings',
                currencyCode: 'EUR',
                hasAlert: false,
            },
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
            transfer: null,
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

function renderPage(
    data: ForecastData,
    projectionInput: ProjectionInput | null = null,
) {
    return render(
        <ForecastPage forecast={data} projectionInput={projectionInput} />,
    );
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

    it('tells a line still to come from one that is overdue', () => {
        const pending = (id: string, date: string) =>
            line({
                id,
                name: id,
                current: { planned: -100, real: null, date, status: 'pending' },
            });
        renderPage(
            forecast({
                plan: {
                    income: [],
                    expenses: [
                        {
                            ...home,
                            items: [
                                pending('Gym', '2026-11-02'),
                                pending('Phone', '2026-11-28'),
                            ],
                        },
                    ],
                    transfers: [],
                },
                alert: {
                    level: 'ok',
                    month: null,
                    min: null,
                    minDate: null,
                    softMonths: [],
                    causes: [],
                    transfer: null,
                },
            }),
        );
        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );

        expect(screen.getByText('expected on the 2')).toBeInTheDocument();
        expect(screen.getByText('arrives on the 28')).toBeInTheDocument();
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
                    transfer: null,
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
            transfer: null,
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

describe('editing the forecast', () => {
    /** The engine's input for the fixture: rent, salary and a yearly insurance on checking. */
    function editingInput(): ProjectionInput {
        const base: ProjectionInput = {
            accounts: [
                { id: 'checking', openingBalance: 100000 },
                { id: 'savings', openingBalance: 900000 },
            ],
            occurrences: [],
            estimates: [],
            startDate: '2026-11-26',
            horizonMonths: 7,
        };
        const rent = forecast().plan.expenses[0].items[0].definition;

        return withLine(
            withLine(withLine(base, 'rent', [rent]), 'salary', [
                {
                    ...rent,
                    id: 'salary',
                    anchorDate: '2026-01-01',
                    versions: [{ effectiveFrom: '2026-01-01', amount: 300000 }],
                },
            ]),
            'insurance',
            [
                {
                    ...rent,
                    id: 'insurance',
                    frequency: 'yearly',
                    anchorDate: '2027-04-03',
                    versions: [
                        { effectiveFrom: '2027-04-03', amount: -705000 },
                    ],
                },
            ],
        );
    }

    function editable(overrides: Partial<ForecastData> = {}): ForecastData {
        const base = forecast(overrides);
        const [rent, insurance] = base.plan.expenses[0].items;

        return {
            ...base,
            projection: accountMonths(editingInput(), 'checking'),
            plan: {
                ...base.plan,
                expenses: [
                    {
                        ...base.plan.expenses[0],
                        items: [
                            {
                                ...rent,
                                occurrenceDates: Object.fromEntries(
                                    [...MONTHS, '2027-05'].map((month) => [
                                        month,
                                        `${month}-05`,
                                    ]),
                                ),
                                drift: { amount: -201000, from: '2026-12-05' },
                            },
                            {
                                ...insurance,
                                definition: {
                                    ...insurance.definition,
                                    id: 'insurance',
                                    frequency: 'yearly',
                                    anchorDate: '2027-04-03',
                                },
                                occurrenceDates: { '2027-04': '2027-04-03' },
                            },
                        ],
                    },
                ],
            },
        };
    }

    function lastVisit() {
        return visit.mock.calls.at(-1) as [
            string,
            {
                method: string;
                data: Record<string, unknown>;
                only: string[];
                optimistic: (props: unknown) => {
                    forecast: ForecastData;
                    projectionInput: ProjectionInput;
                };
            },
        ];
    }

    function sheet(test: string): HTMLElement {
        return document.querySelector(`[data-test="${test}"]`) as HTMLElement;
    }

    it('opens the line of a month, shows what the change does and saves it optimistically', () => {
        visit.mockClear();
        const data = editable();
        renderPage(data, editingInput());

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-line:rent-2027-03"]',
            )!,
        );

        const edit = sheet('forecast-line-sheet');
        expect(edit).toHaveTextContent('Rent · March 2027');
        expect(edit).toHaveTextContent('Before: €1900');
        expect(edit).toHaveTextContent('March 2027 onwards');

        fireEvent.change(within(edit).getByLabelText('Amount'), {
            target: { value: '2000' },
        });
        expect(within(edit).getByRole('status')).toHaveTextContent(
            'With this change, the minimum of April drops from €-550 to €-750.',
        );

        fireEvent.click(within(edit).getByRole('button', { name: 'Save' }));

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/rent');
        expect(options.method).toBe('patch');
        expect(options.only).toEqual(['forecast', 'projectionInput']);
        expect(options.data).toMatchObject({
            scope: 'from_here',
            occurrence_date: '2027-03-05',
            amount: 200000,
        });

        const optimistic = options.optimistic({
            forecast: data,
            projectionInput: editingInput(),
        });
        expect(optimistic.forecast.alert).toMatchObject({
            level: 'strong',
            month: '2027-04',
            min: -75000,
        });
        expect(
            optimistic.forecast.plan.expenses[0].items[0].amounts,
        ).toMatchObject({ '2027-02': -190000, '2027-03': -200000 });
    });

    it('stops forecasting a line', () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-line:insurance-2027-04"]',
            )!,
        );
        fireEvent.click(
            within(sheet('forecast-line-sheet')).getByRole('button', {
                name: 'Stop forecasting it',
            }),
        );
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Stop forecasting it',
            }),
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/insurance');
        expect(options.method).toBe('delete');
        expect(
            options.optimistic({
                forecast: editable(),
                projectionInput: editingInput(),
            }).forecast.alert.level,
        ).toBe('ok');
    });

    it('opens the suggested transfer from the warning, prefilled, with before and after', () => {
        visit.mockClear();
        const transfer = {
            fromAccountId: 'savings',
            amount: 60000,
            date: '2027-04-01',
            month: '2027-04',
        };
        const data = editable();
        renderPage(
            { ...data, alert: { ...data.alert, transfer } },
            editingInput(),
        );

        expect(
            document.querySelector(
                '[data-test="forecast-cell-suggestion:transfer-2027-04"]',
            ),
        ).toHaveTextContent('+600?');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Plan a transfer of €600 from Savings',
            }),
        );

        const add = sheet('forecast-add-sheet');
        expect(
            within(add).getByRole('radio', { name: 'Transfer' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'This is how April looks',
        );
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'Checking · min.€-550→€50',
        );
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'Total of your accounts',
        );

        fireEvent.click(
            within(add).getByRole('button', { name: 'Add transfer' }),
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items');
        expect(options.method).toBe('post');
        expect(options.data).toMatchObject({
            type: 'transfer',
            account_id: 'savings',
            destination_account_id: 'checking',
            destination_external: false,
            amount: 60000,
            date: '2027-04-01',
            frequency: 'once',
        });
    });

    it('only opens the months that can still change', () => {
        renderPage(editable(), editingInput());

        expect(
            document.querySelector(
                '[data-test="forecast-cell-line:rent-2026-11"]',
            ),
        ).toBeNull();
        expect(
            document.querySelector(
                '[data-test="forecast-cell-line:insurance-2027-03"]',
            ),
        ).toBeNull();
    });

    it('opens nothing without the projection input', () => {
        renderPage(editable());

        expect(
            document.querySelector('[data-test^="forecast-cell-"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="forecast-add"]'),
        ).toBeDisabled();
    });

    it('adds a line in a category, adjusts an estimate and changes the wanted minimum', () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            screen.getByRole('button', {
                name: '+ Add something concrete in Home',
            }),
        );
        expect(
            within(sheet('forecast-add-sheet')).getByRole('radio', {
                name: 'Expense',
            }),
        ).toHaveAttribute('aria-checked', 'true');
        fireEvent.click(
            within(sheet('forecast-add-sheet')).getByRole('button', {
                name: 'Cancel',
            }),
        );

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-estimate:home-2027-01"]',
            )!,
        );
        const estimate = sheet('forecast-estimate-sheet');
        expect(estimate).toHaveTextContent(
            'Estimated rest of Home · January 2027',
        );
        fireEvent.change(
            within(estimate).getByLabelText('What you expect to spend'),
            {
                target: { value: '100' },
            },
        );
        fireEvent.click(within(estimate).getByRole('button', { name: 'Save' }));
        expect(lastVisit()[1].data).toEqual({
            account_id: 'checking',
            category_id: 'home',
            month: '2027-01',
            amount: 10000,
            scope: 'from_here',
        });

        fireEvent.click(
            document.querySelector('[data-test="forecast-change-minimum"]')!,
        );
        expect(sheet('forecast-account-sheet')).toHaveTextContent(
            'Wanted minimum',
        );
    });

    it("accepts the update a line's last payments suggest", () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            document.querySelector('[data-test="forecast-drift-rent"]')!,
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/rent/drift');
        expect(options.method).toBe('post');
    });
});
