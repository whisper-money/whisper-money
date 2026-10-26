import { type ServerTransaction } from '@/types/transaction';
import {
    type ColumnDef,
    getCoreRowModel,
    type SortingState,
    useReactTable,
} from '@tanstack/react-table';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TransactionSortMenu } from './transaction-sort-menu';

const COLUMNS: ColumnDef<ServerTransaction>[] = [
    { id: 'select', cell: () => null },
    {
        id: 'transaction_date',
        accessorKey: 'transaction_date',
        meta: { label: 'Date' },
    },
    { id: 'description', accessorKey: 'description' },
    {
        id: 'creditor_name',
        accessorKey: 'creditor_name',
        meta: { label: 'Creditor' },
    },
];

function SortedList({
    onSortingChange,
}: {
    onSortingChange: (sorting: SortingState) => void;
}) {
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const table = useReactTable({
        data: [],
        columns: COLUMNS,
        getCoreRowModel: getCoreRowModel(),
        onSortingChange: (updater) =>
            setSorting((previous) => {
                const next =
                    typeof updater === 'function' ? updater(previous) : updater;
                onSortingChange(next);

                return next;
            }),
        state: { sorting },
    });

    return <TransactionSortMenu table={table} sorting={sorting} />;
}

function openMenu() {
    // Radix opens its menus on pointer down, not on click.
    fireEvent.pointerDown(screen.getByTestId('transaction-sort-menu'), {
        button: 0,
        ctrlKey: false,
    });
}

describe('TransactionSortMenu', () => {
    it('offers the columns the table sorts by from its headings', () => {
        render(<SortedList onSortingChange={vi.fn()} />);

        expect(screen.getByTestId('transaction-sort-menu')).toHaveTextContent(
            'Date',
        );

        openMenu();

        expect(
            screen
                .getAllByRole('menuitemradio')
                .map((item) => item.textContent),
        ).toEqual(['Date', 'Creditor', 'Descending', 'Ascending']);
    });

    it('sorts a name from A and keeps the column when flipping the order', () => {
        const onSortingChange = vi.fn();
        render(<SortedList onSortingChange={onSortingChange} />);

        openMenu();
        fireEvent.click(
            screen.getByRole('menuitemradio', { name: 'Creditor' }),
        );

        expect(onSortingChange).toHaveBeenLastCalledWith([
            { id: 'creditor_name', desc: false },
        ]);

        openMenu();
        fireEvent.click(
            screen.getByRole('menuitemradio', { name: 'Descending' }),
        );

        expect(onSortingChange).toHaveBeenLastCalledWith([
            { id: 'creditor_name', desc: true },
        ]);
    });
});
