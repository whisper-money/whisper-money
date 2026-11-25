import { type DecryptedTransaction } from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { type Table } from '@tanstack/react-table';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionTable } from './transaction-table';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    usePage: () => ({ props: { features: { transactionSplitting: true } } }),
}));

const viewport = vi.hoisted(() => ({ isMobile: false }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => viewport.isMobile }));

// The heavy pieces have tests of their own; here they only show what the
// shell hands them.
vi.mock('@/components/transactions/responsive-transaction-table', () => ({
    ResponsiveTransactionTable: ({
        table,
        topRow,
    }: {
        table: Table<DecryptedTransaction>;
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
    BulkActionsBar: ({
        selectedCount,
        onRemoveAllLabels,
        onSelectAll,
    }: {
        selectedCount: number;
        onRemoveAllLabels: () => void;
        onSelectAll?: () => void;
    }) => (
        <div>
            <div data-testid="bulk-bar">{selectedCount} selected</div>
            <button onClick={onSelectAll}>select all matching</button>
            <button onClick={onRemoveAllLabels}>ask to remove labels</button>
        </div>
    ),
}));
vi.mock('@/components/transactions/transaction-filters', () => ({
    TransactionFilters: ({
        actions,
        trailing,
    }: {
        actions: React.ReactNode;
        trailing: React.ReactNode;
    }) => (
        <div data-testid="filters">
            <div data-testid="page-toolbar">{actions}</div>
            <div data-testid="filters-trailing">{trailing}</div>
        </div>
    ),
}));
const rowDialogs = vi.hoisted(() => ({
    latest: null as {
        onSplitChanged: () => void;
        editing: { canStartSplits: boolean };
    } | null,
}));
vi.mock('@/components/transactions/transaction-row-dialogs', () => ({
    TransactionRowDialogs: (props: {
        onSplitChanged: () => void;
        editing: { canStartSplits: boolean };
    }) => {
        rowDialogs.latest = props;

        return null;
    },
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
] as DecryptedTransaction[];

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
        facets: [],
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
        countMatching: vi.fn().mockResolvedValue(0),
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

    afterEach(() => {
        vi.unstubAllGlobals();
        viewport.isMobile = false;
    });

    it('draws the source rows, the page toolbar, banner and footer', () => {
        renderTable(source());

        expect(screen.getByText('clear selection')).toBeTruthy();
        expect(screen.getByRole('button', { name: /columns/i })).toBeTruthy();
        expect(screen.getByText('banner')).toBeTruthy();
        expect(screen.getByText('tick a')).toBeTruthy();
        expect(screen.getByText('2 transactions loaded')).toBeTruthy();
        expect(screen.getByText('elsewhere')).toBeTruthy();
    });

    it('closes the filters toolbar with the column picker on desktop', () => {
        renderTable(source());

        expect(
            within(screen.getByTestId('filters-trailing')).getByRole('button', {
                name: /columns/i,
            }),
        ).toBeTruthy();
        expect(
            within(screen.getByTestId('page-toolbar')).queryByRole('button', {
                name: /columns/i,
            }),
        ).toBeNull();
    });

    it('hands the column picker to the page toolbar on a phone', () => {
        viewport.isMobile = true;
        renderTable(source());

        expect(
            within(screen.getByTestId('page-toolbar')).getByRole('button', {
                name: /columns/i,
            }),
        ).toBeTruthy();
        expect(screen.getByTestId('filters-trailing').textContent).toBe('');
    });

    it("brings a split's parts in with the source's own reload, and offers splitting while the feature is on", () => {
        const tableSource = source();
        renderTable(tableSource);

        act(() => rowDialogs.latest?.onSplitChanged());

        expect(tableSource.reload).toHaveBeenCalledOnce();
        expect(rowDialogs.latest?.editing.canStartSplits).toBe(true);
    });

    // Merging two ticked parts takes both rows away: the bar must not go on
    // counting ids that no longer exist.
    it('drops the ticked rows when a split or a merge replaces them', () => {
        const tableSource = source();
        renderTable(tableSource);

        fireEvent.click(screen.getByText('tick a'));
        fireEvent.click(screen.getByText('tick b'));
        expect(screen.getByTestId('bulk-bar').textContent).toBe('2 selected');

        act(() => rowDialogs.latest?.onSplitChanged());

        expect(screen.getByTestId('bulk-bar').textContent).toBe('0 selected');
        expect(tableSource.reload).toHaveBeenCalledOnce();
    });

    it('lets the page say how a split brings its parts in', () => {
        const onSplitChanged = vi.fn();
        const tableSource = source();

        render(
            <TransactionTable
                source={tableSource}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                automationRules={[]}
                onSplitChanged={onSplitChanged}
            />,
        );

        act(() => rowDialogs.latest?.onSplitChanged());

        expect(onSplitChanged).toHaveBeenCalledOnce();
        expect(tableSource.reload).not.toHaveBeenCalled();
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

    it('leaves the filters out with hideFilters, and needs no toolbar', () => {
        render(
            <TransactionTable
                source={source()}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                automationRules={[]}
                hideFilters
            />,
        );

        expect(screen.queryByTestId('filters')).toBeNull();
        expect(screen.queryByRole('button', { name: /columns/i })).toBeNull();
        expect(screen.getByText('tick a')).toBeTruthy();
    });

    it('says how many rows are ticked, and draws its bar in the page layout when asked', () => {
        const onSelectionChange = vi.fn();
        const layout = document.createElement('div');
        layout.dataset.slot = 'sidebar-wrapper';
        document.body.append(layout);

        const { container } = render(
            <TransactionTable
                source={source()}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                automationRules={[]}
                hideFilters
                portalBulkActionsBar
                onSelectionChange={onSelectionChange}
            />,
        );

        fireEvent.click(screen.getByText('tick a'));

        expect(onSelectionChange).toHaveBeenLastCalledWith(1);
        expect(within(layout).getByTestId('bulk-bar')).toBeTruthy();
        expect(within(container).queryByTestId('bulk-bar')).toBeNull();
        layout.remove();
    });

    it('lets the toolbar clear the ticked rows', () => {
        renderTable(source());

        fireEvent.click(screen.getByText('tick b'));
        act(() => fireEvent.click(screen.getByText('clear selection')));

        expect(screen.getByTestId('bulk-bar').textContent).toBe('0 selected');
    });

    it('asks before the bulk bar takes the labels off the ticked rows', () => {
        renderTable(source());

        fireEvent.click(screen.getByText('tick b'));

        expect(screen.queryByRole('alertdialog')).toBeNull();

        fireEvent.click(screen.getByText('ask to remove labels'));

        expect(
            within(screen.getByRole('alertdialog')).getByText(
                'Are you sure you want to remove every label from 1 transactions? This action cannot be undone.',
            ),
        ).toBeTruthy();
    });

    it('counts through the source before clearing every row of a select-all', async () => {
        const countMatching = vi.fn().mockResolvedValue(8350);
        renderTable(source({ countMatching, matchingFilters: {} }));

        fireEvent.click(screen.getByText('tick b'));
        fireEvent.click(screen.getByText('select all matching'));
        fireEvent.click(screen.getByText('ask to remove labels'));

        expect(
            await screen.findByText(
                'No filter is set: this removes every label from all your 8350 transactions. This action cannot be undone.',
            ),
        ).toBeTruthy();
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
