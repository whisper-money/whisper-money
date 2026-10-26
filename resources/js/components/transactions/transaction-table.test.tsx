import { type ServerTransaction } from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { type Table } from '@tanstack/react-table';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionTable } from './transaction-table';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

// The heavy pieces have tests of their own; here they only show what the
// shell hands them.
vi.mock('@/components/transactions/responsive-transaction-table', () => ({
    ResponsiveTransactionTable: ({
        table,
        topRow,
    }: {
        table: Table<ServerTransaction>;
        topRow?: React.ReactNode;
    }) => (
        <div data-testid="table">
            {topRow}
            {table.getRowModel().rows.map((row) => (
                <button key={row.id} onClick={() => row.toggleSelected()}>
                    tick {row.id}
                </button>
            ))}
        </div>
    ),
}));
vi.mock('@/components/transactions/bulk-actions-bar', () => ({
    BulkActionsBar: ({ selectedCount }: { selectedCount: number }) => (
        <div data-testid="bulk-bar">{selectedCount} selected</div>
    ),
}));
vi.mock('@/components/transactions/transaction-filters', () => ({
    TransactionFilters: ({ actions }: { actions: React.ReactNode }) => (
        <div data-testid="filters">{actions}</div>
    ),
}));
vi.mock('@/components/transactions/transaction-row-dialogs', () => ({
    TransactionRowDialogs: () => null,
}));
vi.mock('@/components/transactions/transaction-delete-dialogs', () => ({
    TransactionDeleteDialogs: () => null,
}));
vi.mock('@/components/transactions/transaction-columns', () => ({
    createTransactionColumns: () => [
        { id: 'description', accessorKey: 'description' },
    ],
}));

const ROWS = [
    { id: 'a', description: 'Coffee', transaction_date: '2026-10-01' },
    { id: 'b', description: 'Bakery', transaction_date: '2026-10-01' },
] as ServerTransaction[];

function source(
    overrides: Partial<TransactionTableSource> = {},
): TransactionTableSource {
    return {
        rows: ROWS,
        heldRows: ROWS,
        setRows: vi.fn(),
        isLoading: false,
        filters: {} as TransactionTableSource['filters'],
        onFiltersChange: vi.fn(),
        sorting: [{ id: 'transaction_date', desc: true }],
        onSortingChange: vi.fn(),
        manualSorting: true,
        daySummaryOf: () => ({ count: 0, total: { status: 'pending' } }),
        pagination: {
            displayedCount: 2,
            rowCountLabel: 'transactions loaded',
            hasMore: true,
            isLoadingMore: false,
            loadMore: vi.fn(),
            loadsOnScroll: false,
        },
        reload: vi.fn(),
        queryKey: 0,
        ...overrides,
    };
}

function renderTable(tableSource: TransactionTableSource) {
    return render(
        <TransactionTable
            source={tableSource}
            categories={[]}
            accounts={[]}
            banks={[]}
            labels={[]}
            automationRules={[]}
            renderToolbar={({ columnPicker, clearSelection }) => (
                <>
                    <button onClick={clearSelection}>clear selection</button>
                    {columnPicker}
                </>
            )}
            topRow={<p>banner</p>}
            footerActions={<a href="/elsewhere">elsewhere</a>}
        />,
    );
}

describe('TransactionTable', () => {
    let observe: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        observe = vi.fn();
        vi.stubGlobal(
            'IntersectionObserver',
            vi.fn(() => ({ observe, disconnect: vi.fn() })),
        );
    });

    afterEach(() => vi.unstubAllGlobals());

    it('draws the source rows, the page toolbar, banner and footer', () => {
        renderTable(source());

        expect(screen.getByText('clear selection')).toBeTruthy();
        expect(screen.getByRole('button', { name: /columns/i })).toBeTruthy();
        expect(screen.getByText('banner')).toBeTruthy();
        expect(screen.getByText('tick a')).toBeTruthy();
        expect(screen.getByText('2 transactions loaded')).toBeTruthy();
        expect(screen.getByText('elsewhere')).toBeTruthy();
    });

    it('draws the skeleton while the source loads', () => {
        renderTable(source({ isLoading: true }));

        expect(screen.queryByTestId('table')).toBeNull();
        expect(screen.queryByText('Load more')).toBeNull();
    });

    it('drops the ticked rows when the source starts a new query', () => {
        const first = source();
        const { rerender } = renderTable(first);

        fireEvent.click(screen.getByText('tick a'));
        expect(screen.getByTestId('bulk-bar').textContent).toBe('1 selected');

        rerender(
            <TransactionTable
                source={{ ...first, queryKey: 1 }}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                automationRules={[]}
                renderToolbar={() => null}
            />,
        );

        expect(screen.getByTestId('bulk-bar').textContent).toBe('0 selected');
    });

    it('lets the toolbar clear the ticked rows', () => {
        renderTable(source());

        fireEvent.click(screen.getByText('tick b'));
        act(() => fireEvent.click(screen.getByText('clear selection')));

        expect(screen.getByTestId('bulk-bar').textContent).toBe('0 selected');
    });

    it('loads more by itself only when the source pages that way', () => {
        const loadMore = vi.fn();
        const onScroll = source();
        onScroll.pagination = {
            ...onScroll.pagination,
            loadMore,
            loadsOnScroll: true,
        };

        const { unmount } = renderTable(source());
        expect(observe).not.toHaveBeenCalled();
        unmount();

        renderTable(onScroll);
        expect(observe).toHaveBeenCalledOnce();

        fireEvent.click(screen.getByText('Load more'));
        expect(loadMore).toHaveBeenCalledOnce();
    });
});
