import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import type { CreditCardPayment } from '@/types/account';
import type { ServerTransaction } from '@/types/transaction';
import {
    type CellContext,
    type ColumnDef,
    flexRender,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { createTransactionColumns } from './transaction-columns';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

function categoryClassName(columns: ColumnDef<ServerTransaction>[]): string {
    const category = columns.find(
        (column) =>
            'accessorKey' in column && column.accessorKey === 'category_id',
    );

    return (category?.meta as { cellClassName?: string }).cellClassName ?? '';
}

function buildColumnsOptions() {
    return {
        categories: [],
        accounts: [],
        banks: [],
        labels: [],
        locale: 'en',
        onEdit: () => {},
        onDuplicate: () => {},
        onDelete: () => {},
        onUpdate: () => {},
        onReEvaluateRules: () => {},
        onAutomate: () => {},
        onSplit: () => {},
        onUnsplit: () => {},
    };
}

function buildColumns(
    isDateHidden: boolean,
    statementPayments?: Map<string, CreditCardPayment>,
) {
    return createTransactionColumns({
        ...buildColumnsOptions(),
        isDateHidden,
        statementPayments,
    });
}

describe('createTransactionColumns Date column while grouped by day', () => {
    function dateColumn(isGroupedByDay: boolean) {
        return createTransactionColumns({
            ...buildColumnsOptions(),
            isGroupedByDay,
        }).find((column) => column.id === 'transaction_date');
    }

    it('cannot be toggled back while the day headers carry the date', () => {
        expect(dateColumn(true)?.enableHiding).toBe(false);
    });

    it('can be toggled under any other sort', () => {
        expect(dateColumn(false)?.enableHiding).toBe(true);
    });

    function CategoryHeading({ isGroupedByDay }: { isGroupedByDay: boolean }) {
        const table = useReactTable({
            data: [],
            columns: createTransactionColumns({
                ...buildColumnsOptions(),
                isGroupedByDay,
            }),
            getCoreRowModel: getCoreRowModel(),
            manualSorting: true,
            state: {
                sorting: [{ id: 'transaction_date', desc: true }],
                columnVisibility: { transaction_date: !isGroupedByDay },
            },
        });
        const header = table
            .getFlatHeaders()
            .find((candidate) => candidate.column.id === 'category_id');

        return (
            <div>
                {header &&
                    flexRender(
                        header.column.columnDef.header,
                        header.getContext(),
                    )}
            </div>
        );
    }

    it('keeps the date order reachable from the Category heading while hidden', () => {
        render(<CategoryHeading isGroupedByDay />);

        expect(
            screen.getByRole('button', { name: 'Date' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Category')).toBeInTheDocument();
    });

    it('leaves the Category heading alone under any other sort', () => {
        render(<CategoryHeading isGroupedByDay={false} />);

        expect(screen.queryByRole('button', { name: 'Date' })).toBeNull();
        expect(screen.getByText('Category')).toBeInTheDocument();
    });
});

describe('createTransactionColumns category padding', () => {
    it('collapses the Category left padding while the Date column is shown', () => {
        const className = categoryClassName(buildColumns(false));

        expect(className).toContain('pl-0');
        expect(className).not.toContain('pl-2');
    });

    it('restores the Category left padding when the Date column is hidden', () => {
        const className = categoryClassName(buildColumns(true));

        expect(className).toContain('pl-2');
        expect(className).not.toContain('pl-0');
    });
});

describe('createTransactionColumns amount', () => {
    it('shows the amount in the currency the transaction was made in', () => {
        const amount = buildColumns(false).find(
            (column) =>
                'accessorKey' in column && column.accessorKey === 'amount',
        );

        const transaction = {
            amount: -1200,
            // The account is in euros; the row is not, and the table shows the
            // row as it stands rather than converting it.
            currency_code: 'USD',
        } as ServerTransaction;

        render(
            <PrivacyModeProvider>
                {(
                    amount?.cell as (
                        context: CellContext<ServerTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: {
                        getValue: () => transaction.amount,
                        original: transaction,
                    },
                } as unknown as CellContext<ServerTransaction, unknown>)}
            </PrivacyModeProvider>,
        );

        expect(screen.getByText(/\$/)).toBeInTheDocument();
        expect(screen.queryByText(/€/)).not.toBeInTheDocument();
    });
});

describe('createTransactionColumns statement payments', () => {
    const payment: CreditCardPayment = {
        transaction_id: 'payment-1',
        date: '2026-09-13',
        amount: 96335,
        period_from: '2026-08-07',
        closing_date: '2026-09-06',
        due_date: '2026-09-26',
    };

    function renderDescription(
        transaction: ServerTransaction,
        statementPayments?: Map<string, CreditCardPayment>,
    ) {
        const description = buildColumns(false, statementPayments).find(
            (column) =>
                'accessorKey' in column && column.accessorKey === 'description',
        );

        render(
            <PrivacyModeProvider>
                {(
                    description?.cell as (
                        context: CellContext<ServerTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: { original: transaction },
                    table: { getState: () => ({ columnVisibility: {} }) },
                } as unknown as CellContext<ServerTransaction, unknown>)}
            </PrivacyModeProvider>,
        );
    }

    const row = (id: string) =>
        ({
            id,
            description: 'Merpag Débito -> BBVA Crédito',
            label_ids: [],
            notes: null,
        }) as unknown as ServerTransaction;

    it('notes the statement a repayment pays under its description', () => {
        renderDescription(
            row('payment-1'),
            new Map([[payment.transaction_id, payment]]),
        );

        const note = screen.getByTestId('statement-payment-note');
        // Its own line under the description, not a pill beside it.
        expect(
            screen
                .getByText('Merpag Débito -> BBVA Crédito')
                .compareDocumentPosition(note) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(note.querySelector('svg')).not.toBeNull();
        expect(note).toHaveTextContent(
            /^Statement payment Aug 7(, 2026)? – Sep 6(, 2026)?$/,
        );
    });

    it('leaves other rows without the note', () => {
        renderDescription(
            row('purchase-1'),
            new Map([[payment.transaction_id, payment]]),
        );

        expect(
            screen.queryByTestId('statement-payment-note'),
        ).not.toBeInTheDocument();
    });

    it('shows no note outside a credit card page', () => {
        renderDescription(row('payment-1'));

        expect(
            screen.queryByTestId('statement-payment-note'),
        ).not.toBeInTheDocument();
    });
});

describe('createTransactionColumns select checkboxes', () => {
    function renderSelectCheckboxes() {
        const select = buildColumns(false).find(
            (column) => column.id === 'select',
        );
        const table = {
            getIsAllPageRowsSelected: () => false,
            getIsSomePageRowsSelected: () => false,
            toggleAllPageRowsSelected: () => {},
        };
        const row = { getIsSelected: () => true, toggleSelected: () => {} };

        render(
            <>
                {(select?.header as (context: unknown) => React.ReactNode)({
                    table,
                })}
                {(select?.cell as (context: unknown) => React.ReactNode)({
                    row,
                })}
            </>,
        );

        return {
            header: screen.getByRole('checkbox', { name: 'Select all' }),
            row: screen.getByRole('checkbox', { name: 'Select row' }),
        };
    }

    it('keeps the desktop checkbox on the phone, only a size up', () => {
        const { header, row } = renderSelectCheckboxes();

        expect(row.className).toContain('max-md:size-5');
        expect(row.className).not.toContain('rounded-full');
        expect(row.className).not.toContain('max-md:border-2');
        expect(header.className).toBe(row.className);
    });
});
