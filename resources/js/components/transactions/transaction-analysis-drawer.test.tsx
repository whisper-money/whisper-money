import { type TransactionFilters } from '@/types/transaction';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
    over_time: { bucket: 'day', points: [] },
};

function mockAnalysisFetch() {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => analysisResponse,
    }) as unknown as typeof fetch;
}

// The Avg/day card is the 4th amount rendered (Income, Expenses, Net, Avg).
function avgPerDay(): number {
    return Number(screen.getAllByTestId('amount')[3].textContent);
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
