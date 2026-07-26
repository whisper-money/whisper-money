import { type TransactionFilters } from '@/types/transaction';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    isAdverseChange,
    monthlyRates,
    resolveAnalysisView,
    TransactionAnalysisDrawer,
} from './transaction-analysis-drawer';

const axiosGet = vi.fn();
const axiosPatch = vi.fn();

vi.mock('axios', () => ({
    default: {
        get: (...args: unknown[]) => axiosGet(...args),
        patch: (...args: unknown[]) => axiosPatch(...args),
    },
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span data-testid="amount">{amountInCents}</span>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { chartColorScheme: 'colorful' } }),
}));

const filters: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: ['label-1'],
    creditorName: '',
    debtorName: '',
    searchText: '',
    aiCategorizedOnly: false,
};

// expense 90000 cents over a 90-day span → auto avg = 1000/day.
const analysisResponse = {
    currency: 'USD',
    summary: {
        income: 0,
        expense: 90000,
        net: -90000,
        count: 5,
        days: 90,
        average_expense_per_day: 1000,
    },
    by_category: [],
    distinct_category_count: 0,
    by_tag: [],
    distinct_label_count: 0,
    by_payee: [],
    distinct_payee_count: 0,
    by_account: [],
    distinct_account_count: 0,
    largest_expenses: [],
    over_time: { bucket: 'day', points: [] },
};

function mockAnalysisFetch(response: unknown = analysisResponse) {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => response,
    }) as unknown as typeof fetch;
}

// Every summary card renders its label and its mocked amount in one rounded box.
function cardAmount(label: string): number {
    const card = screen
        .getByText(label)
        .closest('div.rounded-lg') as HTMLElement;
    return Number(within(card).getByTestId('amount').textContent);
}

function avgPerDay(): number {
    return cardAmount('Avg / day');
}

function stubLocalStorage() {
    const store = new Map<string, string>();
    vi.stubGlobal('localStorage', {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => store.set(key, value),
        removeItem: (key: string) => store.delete(key),
        clear: () => store.clear(),
    });
}

beforeEach(() => {
    stubLocalStorage();
    axiosGet.mockReset();
    axiosPatch.mockReset();
    mockAnalysisFetch();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('monthly rates', () => {
    function month(key: string, expense: number, income = 0) {
        return { key, label: key, income, expense, net: income - expense };
    }

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        // Mid-July, so 2026-07 is the calendar month still in progress.
        vi.setSystemTime(new Date('2026-07-15T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('leaves the month in progress out of the average', () => {
        const rates = monthlyRates(
            [
                month('2026-04', 1000),
                month('2026-05', 4000),
                month('2026-06', 4000),
                month('2026-07', 999900),
            ],
            false,
        );

        // (1000 + 4000 + 4000) / 3, with July's part-month figure discarded.
        expect(rates?.average).toBe(3000);
    });

    it('compares the recent window against the whole span', () => {
        const rates = monthlyRates(
            [
                month('2026-02', 1000),
                month('2026-03', 1000),
                month('2026-04', 1000),
                month('2026-05', 4000),
                month('2026-06', 4000),
            ],
            false,
        );

        expect(rates?.average).toBe(2200);
        expect(rates?.recentAverage).toBe(3000);
        expect(rates?.changePercentage).toBe(36);
    });

    it('withholds the recent window when it covers the whole span', () => {
        const rates = monthlyRates(
            [
                month('2026-04', 1000),
                month('2026-05', 1000),
                month('2026-06', 1000),
            ],
            false,
        );

        expect(rates?.average).toBe(1000);
        expect(rates?.recentAverage).toBeNull();
        expect(rates?.changePercentage).toBeNull();
    });

    it('reports nothing without a single completed month', () => {
        expect(monthlyRates([month('2026-07', 5000)], false)).toBeNull();
        expect(monthlyRates([], false)).toBeNull();
    });

    it('sizes the change against a negative net average', () => {
        const rates = monthlyRates(
            [
                month('2026-02', 200),
                month('2026-03', 200),
                month('2026-04', 600),
                month('2026-05', 600),
                month('2026-06', 600),
            ],
            true,
        );

        // Net runs at −440/month overall and −600 recently: 36% further under.
        expect(rates?.average).toBe(-440);
        expect(rates?.recentAverage).toBe(-600);
        expect(rates?.changePercentage).toBe(-36);
        expect(isAdverseChange(rates?.changePercentage ?? null, true)).toBe(
            true,
        );
    });

    it('has no change to report against an average of zero', () => {
        const rates = monthlyRates(
            [
                month('2026-03', 1000, 1000),
                month('2026-04', 1000, 1000),
                month('2026-05', 1000, 1000),
                month('2026-06', 1000, 1000),
            ],
            true,
        );

        expect(rates?.average).toBe(0);
        expect(rates?.changePercentage).toBeNull();
    });

    it('reads a rise as bad for spending and good for a net result', () => {
        expect(isAdverseChange(20, false)).toBe(true);
        expect(isAdverseChange(-20, false)).toBe(false);
        expect(isAdverseChange(20, true)).toBe(false);
        expect(isAdverseChange(-20, true)).toBe(true);
        expect(isAdverseChange(0, false)).toBe(false);
        expect(isAdverseChange(null, false)).toBe(false);
    });

    it('falls back to the bounded shape with nothing to average', () => {
        const view = resolveAnalysisView('trend', 0, 5000, [
            month('2026-07', 5000),
        ]);

        expect(view.resolvedMode).toBe('expense');
        expect(view.trendRates).toBeNull();
    });

    it('switches the trend view to net once income is a real share', () => {
        const view = resolveAnalysisView('trend', 100000, 40000, [
            month('2026-04', 1000, 5000),
            month('2026-05', 1000, 5000),
            month('2026-06', 1000, 5000),
        ]);

        expect(view.showsIncome).toBe(true);
        expect(view.resolvedMode).toBe('trend');
        expect(view.trendRates?.average).toBe(4000);
    });

    it('leaves a bounded request alone', () => {
        const view = resolveAnalysisView('expense', 100000, 40000, [
            month('2026-04', 1000),
        ]);

        expect(view.resolvedMode).toBe('expense');
        expect(view.boundedMode).toBe('expense');
        expect(view.trendRates).toBeNull();
    });
});

describe('TransactionAnalysisDrawer day override', () => {
    it('averages over the auto date span when there is no override', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() => expect(avgPerDay()).toBe(1000));
    });

    it('prefers a matching saved filter day override over the span', async () => {
        axiosGet.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 'saved-1',
                        filters: { label_ids: ['label-1'] },
                        analysis_days: 3,
                    },
                ],
            },
        });

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        // 90000 / 3 = 30000.
        await waitFor(() => expect(avgPerDay()).toBe(30000));
    });

    it('falls back to a browser override when no saved filter matches', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        localStorage.setItem(
            `wm.analysis-days.${JSON.stringify({
                date_from: null,
                date_to: null,
                amount_min: null,
                amount_max: null,
                category_ids: [],
                account_ids: [],
                label_ids: ['label-1'],
                creditor_name: '',
                debtor_name: '',
                search: '',
            })}`,
            '5',
        );

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        // 90000 / 5 = 18000.
        await waitFor(() => expect(avgPerDay()).toBe(18000));
    });

    it('persists a new override to the matched saved filter', async () => {
        axiosGet.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 'saved-1',
                        filters: { label_ids: ['label-1'] },
                        analysis_days: null,
                    },
                ],
            },
        });
        axiosPatch.mockResolvedValue({ data: {} });

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() => expect(avgPerDay()).toBe(1000));

        fireEvent.click(screen.getByLabelText('Adjust number of days'));
        fireEvent.change(screen.getByRole('spinbutton'), {
            target: { value: '6' },
        });
        fireEvent.click(screen.getByText('Apply'));

        await waitFor(() =>
            expect(axiosPatch).toHaveBeenCalledWith(
                '/api/saved-filters/saved-1/analysis-days',
                { analysis_days: 6 },
            ),
        );
        // 90000 / 6 = 15000.
        expect(avgPerDay()).toBe(15000);
    });
});

describe('TransactionAnalysisDrawer view mode', () => {
    const incomeResponse = {
        ...analysisResponse,
        summary: {
            income: 100000,
            expense: 40000,
            net: 60000,
            count: 4,
            days: 30,
            average_expense_per_day: 1333,
        },
    };

    it('auto-detects income mode when income is a meaningful share', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(incomeResponse);

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Net result')).toBeInTheDocument(),
        );
        expect(screen.getByText('Margin')).toBeInTheDocument();
        // 60000 / 100000 = 60%.
        expect(screen.getByText('60%')).toBeInTheDocument();
        expect(screen.queryByText('Avg / day')).not.toBeInTheDocument();
    });

    it('stays in expense mode for a stray refund below the threshold', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch({
            ...analysisResponse,
            summary: {
                income: 5000,
                expense: 90000,
                net: -85000,
                count: 6,
                days: 90,
                average_expense_per_day: 944,
            },
        });

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Avg / day')).toBeInTheDocument(),
        );
        expect(screen.queryByText('Net result')).not.toBeInTheDocument();
    });

    it('persists a forced view mode to the matched saved filter', async () => {
        axiosGet.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 'saved-1',
                        filters: { label_ids: ['label-1'] },
                        analysis_days: null,
                        analysis_mode: null,
                    },
                ],
            },
        });
        axiosPatch.mockResolvedValue({ data: {} });
        mockAnalysisFetch(incomeResponse);

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Net result')).toBeInTheDocument(),
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Income & expenses/i }),
        );
        fireEvent.click(screen.getByText('Total spent'));

        await waitFor(() =>
            expect(axiosPatch).toHaveBeenCalledWith(
                '/api/saved-filters/saved-1/analysis-mode',
                { analysis_mode: 'expense' },
            ),
        );
        await waitFor(() =>
            expect(screen.getByText('Avg / day')).toBeInTheDocument(),
        );
    });
});

describe('TransactionAnalysisDrawer monthly trend view', () => {
    // Feb–Jun are complete; Jul is the calendar month in progress and its
    // deliberately huge figure must not reach either average.
    const monthlyPoints = [
        ['2026-02', 1000],
        ['2026-03', 1000],
        ['2026-04', 1000],
        ['2026-05', 4000],
        ['2026-06', 4000],
        ['2026-07', 999900],
    ].map(([date, expense]) => ({
        date,
        label: date as string,
        income: 0,
        expense: expense as number,
        cumulative_expense: 0,
        cumulative_net: 0,
    }));

    function trendResponse(overrides: Record<string, unknown> = {}) {
        return {
            ...analysisResponse,
            summary: {
                ...analysisResponse.summary,
                count: 40,
                // Long enough for the automatic view to pick the trend shape.
                days: 150,
            },
            over_time: { bucket: 'month', points: monthlyPoints },
            largest_expenses: [
                {
                    id: 'tx-1',
                    date: '2026-05-10',
                    description: 'Grand Hotel',
                    amount: 50000,
                    category: null,
                    account: { name: 'Visa', bank: null },
                    labels: [],
                },
            ],
            ...overrides,
        };
    }

    function renderDrawer() {
        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );
    }

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date('2026-07-15T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('averages completed months only, ignoring the month in progress', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(trendResponse());

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Monthly average')).toBeInTheDocument(),
        );

        // (1000 + 1000 + 1000 + 4000 + 4000) / 5 completed months.
        expect(cardAmount('Monthly average')).toBe(2200);
        // The last three completed months: (1000 + 4000 + 4000) / 3.
        expect(cardAmount('Last 3 months')).toBe(3000);
        // 3000 against 2200.
        expect(screen.getByText('+36%')).toBeInTheDocument();
    });

    it('replaces the bounded widgets with the monthly ones', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(trendResponse());

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Spending per month')).toBeInTheDocument(),
        );

        expect(screen.queryByText('Avg / day')).not.toBeInTheDocument();
        expect(screen.queryByText('Total spent')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Spending over time'),
        ).not.toBeInTheDocument();
        // A single largest expense says nothing across an open-ended span.
        expect(screen.queryByText('Largest expenses')).not.toBeInTheDocument();
        // The footer names the months behind the average instead of a day count.
        expect(
            screen.getByText(/Feb 2026 – Jul 2026/, { exact: false }),
        ).toBeInTheDocument();
    });

    it('keeps the bounded shape below the trend span', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(
            trendResponse({
                summary: {
                    ...analysisResponse.summary,
                    count: 40,
                    days: 119,
                },
            }),
        );

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Avg / day')).toBeInTheDocument(),
        );
        expect(screen.queryByText('Monthly average')).not.toBeInTheDocument();
        expect(screen.getByText('Largest expenses')).toBeInTheDocument();
    });

    it('drops the recent card when it would repeat the overall average', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(
            trendResponse({
                // Three completed months plus the one in progress: the recent
                // window would cover every month it is compared against.
                over_time: { bucket: 'month', points: monthlyPoints.slice(-4) },
            }),
        );

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Monthly average')).toBeInTheDocument(),
        );

        // (1000 + 4000 + 4000) / 3.
        expect(cardAmount('Monthly average')).toBe(3000);
        expect(screen.queryByText('Last 3 months')).not.toBeInTheDocument();
    });

    it('falls back to the bounded view without a completed month', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(
            trendResponse({
                over_time: { bucket: 'month', points: monthlyPoints.slice(-1) },
            }),
        );

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Avg / day')).toBeInTheDocument(),
        );
        expect(screen.queryByText('Monthly average')).not.toBeInTheDocument();
        // The trigger names what is on screen, not the view that was unavailable.
        expect(
            screen.getByRole('button', { name: /Total spent/i }),
        ).toBeInTheDocument();
    });

    it('reports a net rate when income is a meaningful share', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(
            trendResponse({
                summary: {
                    income: 100000,
                    expense: 40000,
                    net: 60000,
                    count: 40,
                    days: 150,
                    average_expense_per_day: 266,
                },
                over_time: {
                    bucket: 'month',
                    points: monthlyPoints
                        .slice(0, 5)
                        .map((point) => ({ ...point, income: 5000 })),
                },
            }),
        );

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Monthly net average')).toBeInTheDocument(),
        );

        // Income 5000 less expense, averaged over the five completed months:
        // (4000 + 4000 + 4000 + 1000 + 1000) / 5.
        expect(cardAmount('Monthly net average')).toBe(2800);
    });

    it('persists a forced trend view to the matched saved filter', async () => {
        axiosGet.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 'saved-1',
                        filters: { label_ids: ['label-1'] },
                        analysis_days: null,
                        analysis_mode: null,
                    },
                ],
            },
        });
        axiosPatch.mockResolvedValue({ data: {} });
        // A bounded span, so the trend view only appears once forced.
        mockAnalysisFetch(
            trendResponse({
                summary: {
                    ...analysisResponse.summary,
                    count: 40,
                    days: 60,
                },
            }),
        );

        renderDrawer();

        await waitFor(() =>
            expect(screen.getByText('Avg / day')).toBeInTheDocument(),
        );

        fireEvent.click(screen.getByRole('button', { name: /Total spent/i }));
        fireEvent.click(screen.getByText('Monthly trend'));

        await waitFor(() =>
            expect(axiosPatch).toHaveBeenCalledWith(
                '/api/saved-filters/saved-1/analysis-mode',
                { analysis_mode: 'trend' },
            ),
        );
        await waitFor(() =>
            expect(screen.getByText('Monthly average')).toBeInTheDocument(),
        );
    });
});

describe('TransactionAnalysisDrawer largest expenses columns', () => {
    function largestExpense(overrides: Record<string, unknown> = {}) {
        return {
            id: 'tx-1',
            date: '2026-01-10',
            description: 'Grand Hotel',
            amount: 50000,
            category: { name: 'Hotel', color: 'blue', icon: 'Building' },
            account: { name: 'Visa', bank: null },
            labels: [{ id: 'l1', name: 'Miami', color: 'blue' }],
            ...overrides,
        };
    }

    it('hides columns the filter or the rows have pinned to one value', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch({
            ...analysisResponse,
            // Two rows sharing one category and one account.
            largest_expenses: [
                largestExpense({ id: 'tx-1' }),
                largestExpense({ id: 'tx-2', description: 'Room service' }),
            ],
        });

        render(
            // filters pins a single label.
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Largest expenses')).toBeInTheDocument(),
        );

        expect(screen.queryByText('Category')).not.toBeInTheDocument();
        expect(screen.queryByText('Account')).not.toBeInTheDocument();
        expect(screen.queryByText('Labels')).not.toBeInTheDocument();
        expect(screen.getByText('Description')).toBeInTheDocument();
    });

    it('keeps columns that vary across the rows', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch({
            ...analysisResponse,
            largest_expenses: [
                largestExpense({ id: 'tx-1' }),
                largestExpense({
                    id: 'tx-2',
                    category: {
                        name: 'Meals',
                        color: 'amber',
                        icon: 'Utensils',
                    },
                    account: { name: 'Amex', bank: null },
                }),
            ],
        });

        render(
            // No label pinned, so the labels column stays too.
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={{ ...filters, labelIds: [] }}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Largest expenses')).toBeInTheDocument(),
        );

        expect(screen.getByText('Category')).toBeInTheDocument();
        expect(screen.getByText('Account')).toBeInTheDocument();
        expect(screen.getByText('Labels')).toBeInTheDocument();
    });
});

describe('TransactionAnalysisDrawer breakdowns', () => {
    const breakdownResponse = {
        ...analysisResponse,
        by_category: [
            {
                category_id: 'c1',
                name: 'Food',
                color: 'amber',
                icon: 'Utensils',
                amount: 40000,
                children: [
                    {
                        category_id: 'c2',
                        name: 'Groceries',
                        color: 'amber',
                        icon: 'ShoppingBag',
                        amount: 30000,
                    },
                ],
            },
            {
                category_id: 'c3',
                name: 'Hotel',
                color: 'blue',
                icon: 'Building',
                amount: 20000,
                children: [],
            },
        ],
        distinct_category_count: 2,
        by_tag: [
            { id: 't1', name: 'Miami', color: 'blue', amount: 10000 },
            { id: 't2', name: 'Snacks', color: 'amber', amount: 3000 },
        ],
        distinct_label_count: 2,
        by_account: [
            {
                id: 'a1',
                name: 'Visa',
                bank: { name: 'Acme', logo: null },
                amount: 5000,
            },
            { id: 'a2', name: 'Amex', bank: null, amount: 3000 },
        ],
        distinct_account_count: 2,
    };

    it('renders a category bar list and expands children on demand', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(breakdownResponse);

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(
                screen.getByText('Spending by category'),
            ).toBeInTheDocument(),
        );
        expect(screen.getByText('Food')).toBeInTheDocument();
        expect(screen.getByText('Hotel')).toBeInTheDocument();

        // The nested category is hidden until its parent is expanded.
        expect(screen.queryByText('Groceries')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Show subcategories' }),
        );

        await waitFor(() =>
            expect(screen.getByText('Groceries')).toBeInTheDocument(),
        );
    });

    it('renders the tag and account bar lists', async () => {
        axiosGet.mockResolvedValue({ data: { data: [] } });
        mockAnalysisFetch(breakdownResponse);

        render(
            <TransactionAnalysisDrawer
                open
                onOpenChange={vi.fn()}
                filters={filters}
            />,
        );

        await waitFor(() =>
            expect(screen.getByText('Spending by tag')).toBeInTheDocument(),
        );
        expect(screen.getByText('Miami')).toBeInTheDocument();
        expect(screen.getByText('Snacks')).toBeInTheDocument();

        expect(screen.getByText('Spending by account')).toBeInTheDocument();
        expect(screen.getByText('Visa')).toBeInTheDocument();
        expect(screen.getByText('Amex')).toBeInTheDocument();
    });
});
