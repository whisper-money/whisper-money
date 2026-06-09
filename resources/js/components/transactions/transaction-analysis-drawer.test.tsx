import { type TransactionFilters } from '@/types/transaction';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionAnalysisDrawer } from './transaction-analysis-drawer';

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

// In expense-only mode the Avg/day amount lives in the card labelled "Avg / day".
function avgPerDay(): number {
    const card = screen
        .getByText('Avg / day')
        .closest('div.rounded-lg') as HTMLElement;
    return Number(within(card).getByTestId('amount').textContent);
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
        fireEvent.click(screen.getByText('Expenses only'));

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
