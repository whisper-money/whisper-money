import { DataTable } from '@/components/ui/data-table';
import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type AmountInUserCurrency } from '@/lib/transaction-day-groups';
import { type ServerTransaction } from '@/types/transaction';
import {
    type ColumnDef,
    getCoreRowModel,
    type RowSelectionState,
    useReactTable,
    type VisibilityState,
} from '@tanstack/react-table';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionDayHeader } from './transaction-day-header';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { user: { currency_code: 'EUR' } }, locale: 'en-US' },
    }),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

// jsdom lays nothing out, so the real virtualizer would render no rows at all.
vi.mock('@tanstack/react-virtual', () => ({
    useVirtualizer: ({
        count,
        getItemKey,
    }: {
        count: number;
        getItemKey: (index: number) => string;
    }) => ({
        getVirtualItems: () =>
            Array.from({ length: count }, (_, index) => ({
                index,
                key: getItemKey(index),
                start: index * 72,
                end: (index + 1) * 72,
                size: 72,
                lane: 0,
            })),
        getTotalSize: () => count * 72,
        measureElement: () => {},
    }),
}));

const TRANSACTIONS = [
    {
        id: 'a',
        transaction_date: '2026-10-26',
        amount: -1250,
        currency_code: 'EUR',
        description: 'Coffee',
    },
    {
        id: 'b',
        transaction_date: '2026-10-26',
        amount: -750,
        currency_code: 'EUR',
        description: 'Bakery',
    },
    {
        id: 'c',
        transaction_date: '2026-10-21',
        amount: 300000,
        currency_code: 'EUR',
        description: 'Salary',
    },
] as ServerTransaction[];

const COLUMNS: ColumnDef<ServerTransaction>[] = [
    { id: 'select', cell: () => null },
    {
        id: 'transaction_date',
        accessorKey: 'transaction_date',
        cell: () => null,
    },
    {
        id: 'description',
        accessorKey: 'description',
        cell: ({ row }) => row.original.description,
    },
    { id: 'amount', accessorKey: 'amount', cell: () => null },
    { id: 'actions', cell: () => null },
    { id: 'labels', meta: { isVirtual: true }, cell: () => null },
];

function TransactionsTable({
    isGroupedByDay = true,
    columnVisibility = {},
    amountInUserCurrency = (transaction) => transaction.amount,
    isPartial = false,
    onSelectionChange = () => {},
}: {
    isPartial?: boolean;
    isGroupedByDay?: boolean;
    columnVisibility?: VisibilityState;
    amountInUserCurrency?: AmountInUserCurrency;
    onSelectionChange?: (selection: RowSelectionState) => void;
}) {
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const table = useReactTable({
        data: TRANSACTIONS,
        columns: COLUMNS,
        getRowId: (row) => row.id,
        getCoreRowModel: getCoreRowModel(),
        onRowSelectionChange: (updater) =>
            setRowSelection((previous) => {
                const next =
                    typeof updater === 'function' ? updater(previous) : updater;
                onSelectionChange(next);

                return next;
            }),
        state: { rowSelection, columnVisibility },
    });

    return (
        <PrivacyModeProvider>
            <DataTable
                table={table}
                columns={COLUMNS}
                groupRowsBy={
                    isGroupedByDay ? (row) => row.transaction_date : undefined
                }
                renderGroupHeader={(group, measureProps) => (
                    <TransactionDayHeader
                        table={table}
                        date={group.key}
                        rows={group.rows}
                        rowSelection={rowSelection}
                        amountInUserCurrency={amountInUserCurrency}
                        isPartial={isPartial}
                        measureProps={measureProps}
                    />
                )}
            />
        </PrivacyModeProvider>
    );
}

function dayHeaders(): HTMLElement[] {
    return screen.queryAllByTestId('transaction-day-header');
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-26T12:00:00'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('TransactionDayHeader', () => {
    it('puts a header above the rows of each day', () => {
        render(<TransactionsTable />);

        const rows = screen.getAllByRole('row').slice(1);

        expect(rows.map((row) => row.textContent)).toEqual([
            expect.stringContaining('Today'),
            'Coffee',
            'Bakery',
            expect.stringContaining('Wednesday'),
            'Salary',
        ]);
        expect(dayHeaders()[0]).toHaveTextContent('Monday, October 26');
        expect(dayHeaders()[0]).toHaveTextContent('2 transactions');
        expect(dayHeaders()[1]).toHaveTextContent('October 21');
        expect(dayHeaders()[1]).toHaveTextContent('1 transaction');
    });

    it('lines the day total up under the Amount column', () => {
        render(<TransactionsTable />);

        // select | date..description | amount | actions
        const cells = within(dayHeaders()[0]).getAllByRole('cell');

        expect(cells).toHaveLength(4);
        expect(cells[1]).toHaveAttribute('colspan', '2');
        expect(cells[2]).toHaveTextContent('-€20.00');
        expect(dayHeaders()[1]).toHaveTextContent('€3,000.00');
    });

    it('spans one column less once the Date column is hidden', () => {
        render(
            <TransactionsTable
                columnVisibility={{ transaction_date: false }}
            />,
        );

        const cells = within(dayHeaders()[0]).getAllByRole('cell');

        expect(cells[1]).toHaveAttribute('colspan', '1');
        expect(cells[2]).toHaveTextContent('-€20.00');
    });

    it('leaves the total out when a rate is missing', () => {
        render(
            <TransactionsTable
                amountInUserCurrency={(transaction) =>
                    transaction.id === 'b' ? null : transaction.amount
                }
            />,
        );

        const total = within(dayHeaders()[0]).getAllByRole('cell')[2];

        expect(total).toHaveTextContent('—');
        expect(total).not.toHaveTextContent('€');
    });

    it('ticks and unticks every loaded row of its day', () => {
        const onSelectionChange = vi.fn();
        render(<TransactionsTable onSelectionChange={onSelectionChange} />);

        const checkbox = within(dayHeaders()[0]).getByRole('checkbox');
        fireEvent.click(checkbox);

        expect(onSelectionChange).toHaveBeenLastCalledWith({
            a: true,
            b: true,
        });
        expect(checkbox).toHaveAttribute('data-state', 'checked');

        fireEvent.click(checkbox);

        expect(onSelectionChange).toHaveBeenLastCalledWith({});
    });

    it('shows a partly ticked day as indeterminate', () => {
        const onSelectionChange = vi.fn();
        render(<TransactionsTable onSelectionChange={onSelectionChange} />);

        // Tick the whole of the older day, then the newer one, then untick it:
        // only rows of the clicked day ever change.
        fireEvent.click(within(dayHeaders()[1]).getByRole('checkbox'));
        expect(onSelectionChange).toHaveBeenLastCalledWith({ c: true });

        fireEvent.click(within(dayHeaders()[0]).getByRole('checkbox'));
        fireEvent.click(within(dayHeaders()[0]).getByRole('checkbox'));
        expect(onSelectionChange).toHaveBeenLastCalledWith({ c: true });
    });

    it('says a day may go on when more rows wait to be loaded', () => {
        render(<TransactionsTable isPartial />);

        expect(dayHeaders()[0]).toHaveTextContent('2 transactions so far');
    });

    it('draws no headers when the table is not sorted by date', () => {
        render(<TransactionsTable isGroupedByDay={false} />);

        expect(dayHeaders()).toHaveLength(0);
        expect(screen.getAllByRole('row')).toHaveLength(4);
    });
});
