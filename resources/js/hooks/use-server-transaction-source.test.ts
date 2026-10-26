import { type CursorPaginatedResponse } from '@/lib/cursor-pagination';
import {
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clientFiltersToQueryParams,
    useServerTransactionSource,
    type AppliedFilters,
} from './use-server-transaction-source';

const { visit, reload } = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit, reload },
    usePage: () => ({
        component: 'transactions/index',
        version: 'v1',
        props: { auth: { user: { currency_code: 'EUR' } } },
    }),
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: ({ query }: { query: Record<string, string> }) => ({
        url: `/transactions?${new URLSearchParams(query)}`,
    }),
}));

const NO_FILTERS: AppliedFilters = {
    date_from: null,
    date_to: null,
    amount_min: null,
    amount_max: null,
    category_ids: [],
    account_ids: [],
    label_ids: [],
    creditor_name: '',
    debtor_name: '',
    category_source: null,
    search: '',
    cashflow_block: null,
    sort: '',
};

function row(id: string, transaction_date = '2026-10-01'): ServerTransaction {
    return {
        id,
        transaction_date: `${transaction_date}T00:00:00.000000Z`,
        amount: -100,
        currency_code: 'EUR',
        labels: [{ id: 'label' }],
        account: { bank: { id: 'bank' } },
    } as unknown as ServerTransaction;
}

function page(
    rows: ServerTransaction[],
    next_cursor: string | null,
): CursorPaginatedResponse<ServerTransaction> {
    return {
        data: rows,
        next_cursor,
        next_page_url: null,
        prev_cursor: null,
        prev_page_url: null,
        per_page: 50,
    };
}

function renderSource(nextCursor: string | null = 'cursor-2') {
    const appliedFilters = { ...NO_FILTERS };
    const transactions = page([row('a')], nextCursor);
    const dayTotals = { '2026-10-01': { count: 4, total: -400 } };

    return renderHook(() =>
        useServerTransactionSource({
            transactions,
            dayTotals,
            appliedFilters,
        }),
    );
}

describe('clientFiltersToQueryParams', () => {
    it('writes only the filters in use, and the sort unless it is the default', () => {
        const filters = {
            dateFrom: new Date(2026, 9, 1),
            dateTo: null,
            amountMin: 0,
            amountMax: null,
            categoryIds: ['food', 'fuel'],
            accountIds: [],
            labelIds: [],
            creditorName: '',
            debtorName: '',
            searchText: 'coffee',
            aiCategorizedOnly: true,
        } as TransactionFilters;

        expect(
            clientFiltersToQueryParams(filters, '-transaction_date'),
        ).toEqual({
            date_from: '2026-10-01',
            amount_min: '0',
            category_ids: 'food,fuel',
            search: 'coffee',
            category_source: 'ai',
        });
        expect(clientFiltersToQueryParams(filters, 'creditor_name').sort).toBe(
            'creditor_name',
        );
    });
});

describe('useServerTransactionSource', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => vi.useRealTimers());

    it('serves the rows trimmed to their day, with labels as ids too', () => {
        const { result } = renderSource();

        expect(result.current.rows[0]).toMatchObject({
            transaction_date: '2026-10-01',
            label_ids: ['label'],
            bank: { id: 'bank' },
        });
        expect(result.current.manualSorting).toBe(true);
        expect(result.current.pagination).toMatchObject({
            displayedCount: 1,
            hasMore: true,
            loadsOnScroll: true,
        });
        expect(result.current.daySummaryOf('2026-10-01')).toEqual({
            count: 4,
            total: { status: 'ready', amount: -400 },
        });
    });

    it('asks the server again on a new sort and starts a new query', () => {
        const { result } = renderSource();

        act(() =>
            result.current.onSortingChange([
                { id: 'creditor_name', desc: true },
            ]),
        );

        expect(visit).toHaveBeenCalledWith(
            '/transactions?sort=-creditor_name',
            expect.objectContaining({ preserveState: true }),
        );
        expect(result.current.isLoading).toBe(true);
        expect(result.current.queryKey).toBe(1);
    });

    it('waits for a pause in typing before searching', () => {
        const { result } = renderSource();

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                searchText: 'cof',
            }),
        );
        expect(visit).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(300));

        expect(visit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][0]).toBe('/transactions?search=cof');
    });

    it('appends the next page and keeps the figure of a day already shown', async () => {
        vi.useRealTimers();
        const fetchMock = vi.fn().mockResolvedValue({
            json: async () => ({
                props: {
                    transactions: page(
                        [row('b'), row('c', '2026-09-30')],
                        null,
                    ),
                    dayTotals: {
                        '2026-10-01': { count: 99, total: -9900 },
                        '2026-09-30': { count: 1, total: -100 },
                    },
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const { result } = renderSource();

        await act(() => result.current.pagination.loadMore());

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/transactions?cursor=cursor-2',
        );
        expect(result.current.rows.map((loaded) => loaded.id)).toEqual([
            'a',
            'b',
            'c',
        ]);
        expect(result.current.pagination.hasMore).toBe(false);
        // The server's 4 already counted the row the next page brings; the
        // later page's 99 for the same day must not replace it.
        expect(result.current.daySummaryOf('2026-10-01').count).toBe(4);
        expect(result.current.daySummaryOf('2026-09-30').count).toBe(1);
        vi.unstubAllGlobals();
    });

    it('reloads only the rows and day totals', () => {
        const { result } = renderSource();

        act(() => result.current.reload());

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['transactions', 'dayTotals'] }),
        );
    });
});
