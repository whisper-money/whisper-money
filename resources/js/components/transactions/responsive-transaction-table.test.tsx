import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type RowSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { summarizeDay } from '@/lib/transaction-day-groups';
import { type ServerTransaction } from '@/types/transaction';
import {
    type ColumnDef,
    getCoreRowModel,
    type RowSelectionState,
    type SortingState,
    useReactTable,
} from '@tanstack/react-table';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ResponsiveTransactionTable } from './responsive-transaction-table';

let listWidth = 600;

vi.mock('@/hooks/use-element-width', () => ({
    useElementWidth: () => [() => undefined, listWidth],
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

let isPhone = false;

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => isPhone }));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({
        props: {
            auth: { user: { currency_code: 'EUR' }, hasProPlan: true },
            subscriptionsEnabled: false,
            aiCategorizationUpsellRate: 0,
            locale: 'en-US',
        },
    }),
}));

// jsdom lays nothing out, so the real virtualizer would render no rows at all.
vi.mock('@tanstack/react-virtual', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useVirtualizer: (await import('@/lib/virtualizer.fixture'))
        .useEveryItemVirtualizer,
}));

const TRANSACTIONS = [
    {
        id: 'a',
        transaction_date: '2026-10-26',
        amount: -1250,
        currency_code: 'EUR',
        description: 'Coffee',
        label_ids: [],
    },
    {
        id: 'b',
        transaction_date: '2026-10-26',
        amount: -750,
        currency_code: 'EUR',
        description: 'Bakery',
        label_ids: [],
    },
    {
        id: 'c',
        transaction_date: '2026-10-21',
        amount: 300000,
        currency_code: 'EUR',
        description: 'Salary',
        label_ids: [],
    },
] as unknown as ServerTransaction[];

const COLUMNS: ColumnDef<ServerTransaction>[] = [
    { id: 'select', cell: () => null },
    {
        id: 'transaction_date',
        accessorKey: 'transaction_date',
        meta: { label: 'Date' },
        cell: () => null,
    },
    {
        id: 'description',
        accessorKey: 'description',
        cell: ({ row }) => row.original.description,
    },
    {
        id: 'creditor_name',
        accessorKey: 'creditor_name',
        meta: { label: 'Creditor' },
        cell: () => null,
    },
];

const rowActions = {
    onEdit: vi.fn(),
    onDuplicate: vi.fn(),
    onReEvaluateRules: vi.fn(),
    onAutomate: vi.fn(),
    onDelete: vi.fn(),
    onSplit: vi.fn(),
    onUnsplit: vi.fn(),
};

function TransactionsList({
    onSortingChange = () => {},
    maxHeight,
    framedOnPhone,
}: {
    onSortingChange?: (sorting: SortingState) => void;
    maxHeight?: number;
    framedOnPhone?: boolean;
}) {
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const table = useReactTable({
        data: TRANSACTIONS,
        columns: COLUMNS,
        getRowId: (row) => row.id,
        getCoreRowModel: getCoreRowModel(),
        onRowSelectionChange: setRowSelection,
        onSortingChange: (updater) =>
            setSorting((previous) => {
                const next =
                    typeof updater === 'function' ? updater(previous) : updater;
                onSortingChange(next);

                return next;
            }),
        state: { rowSelection, sorting },
    });

    const selectionMode: RowSelectionMode = {
        isMobile: false,
        isActive: false,
        enterWith: (rowId) => setRowSelection({ [rowId]: true }),
        toggleRow: (rowId) =>
            setRowSelection(({ [rowId]: wasSelected, ...rest }) =>
                wasSelected ? rest : { ...rest, [rowId]: true },
            ),
    };

    return (
        <PrivacyModeProvider>
            <ResponsiveTransactionTable
                table={table}
                columns={COLUMNS}
                emptyMessage="No transactions found."
                renderRow={(row) => (
                    <tr key={row.id} data-testid="table-row">
                        <td>{row.original.description}</td>
                    </tr>
                )}
                sorting={sorting}
                rowSelection={rowSelection}
                daySummaryOf={(date) =>
                    summarizeDay(
                        TRANSACTIONS.filter(
                            (row) => row.transaction_date === date,
                        ),
                        (transaction) => transaction.amount,
                    )
                }
                isGroupedByDay
                selectionMode={selectionMode}
                listRow={{ categories: [], labels: [] }}
                rowActions={rowActions}
                maxHeight={maxHeight}
                framedOnPhone={framedOnPhone}
            />
        </PrivacyModeProvider>
    );
}

function listRows(): HTMLElement[] {
    return screen.queryAllByTestId('transaction-list-row');
}

function circleOf(description: string): HTMLElement {
    return screen.getByRole('button', { name: `Select ${description}` });
}

beforeEach(() => {
    listWidth = 600;
    isPhone = false;
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-26T12:00:00'));
});

afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
});

describe('ResponsiveTransactionTable', () => {
    it('draws the table when the list has room for it', () => {
        listWidth = 1100;

        render(<TransactionsList />);

        expect(screen.getAllByTestId('table-row')).toHaveLength(3);
        expect(listRows()).toHaveLength(0);
    });

    it('runs the compact list edge to edge on a phone unless it is framed', () => {
        const { unmount } = render(<TransactionsList />);
        const bled = screen.getByTestId('transaction-compact-list-rows');

        expect(bled.className).toContain('max-md:-mx-6');
        expect(bled.className).toContain('max-md:border-x-0');
        unmount();

        render(<TransactionsList framedOnPhone />);
        const framed = screen.getByTestId('transaction-compact-list-rows');

        expect(framed.className).not.toContain('max-md:');
        expect(framed.className).toContain('rounded-md border');
    });

    it('lets an edge-to-edge list grow with the page on a phone instead of scrolling a box', () => {
        isPhone = true;
        const { unmount } = render(<TransactionsList maxHeight={600} />);

        expect(
            screen.getByTestId('transaction-compact-list-rows').style.maxHeight,
        ).toBe('');
        unmount();

        render(<TransactionsList maxHeight={600} framedOnPhone />);

        expect(
            screen.getByTestId('transaction-compact-list-rows').style.maxHeight,
        ).toBe('600px');
    });

    it('turns into the compact list under 56rem, grouped under the same days', () => {
        render(<TransactionsList />);

        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(listRows()).toHaveLength(3);

        const headers = screen.getAllByTestId('transaction-day-header');
        expect(headers).toHaveLength(2);
        expect(headers[0]).toHaveTextContent('Today · Oct 26');
        expect(headers[0]).toHaveTextContent('-€20.00');
    });

    it('selects a row from its circle, after which a tap anywhere toggles', () => {
        render(<TransactionsList />);

        fireEvent.click(circleOf('Coffee'));

        expect(circleOf('Coffee')).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(within(listRows()[2]).getByText('Salary'));

        expect(circleOf('Salary')).toHaveAttribute('aria-pressed', 'true');
        expect(rowActions.onEdit).not.toHaveBeenCalled();
    });

    it('edits a row on a tap while nothing is selected', () => {
        render(<TransactionsList />);

        fireEvent.click(within(listRows()[1]).getByText('Bakery'));

        expect(rowActions.onEdit).toHaveBeenCalledWith(TRANSACTIONS[1]);
    });

    it('swaps the day total for selecting the whole day while selecting', () => {
        render(<TransactionsList />);

        expect(
            screen.queryByRole('button', { name: 'Select day' }),
        ).not.toBeInTheDocument();

        fireEvent.click(circleOf('Salary'));
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Select day' })[0],
        );

        expect(circleOf('Coffee')).toHaveAttribute('aria-pressed', 'true');
        expect(circleOf('Bakery')).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Deselect day' })[0],
        );

        expect(circleOf('Coffee')).toHaveAttribute('aria-pressed', 'false');
        expect(circleOf('Salary')).toHaveAttribute('aria-pressed', 'true');
    });
});
