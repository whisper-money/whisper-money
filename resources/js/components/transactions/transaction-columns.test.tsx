import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import type { CreditCardPayment } from '@/types/account';
import type { DecryptedTransaction } from '@/types/transaction';
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

function categoryClassName(columns: ColumnDef<DecryptedTransaction>[]): string {
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
        onEdit: () => {},
        onDuplicate: () => {},
        onDelete: () => {},
        onUpdate: () => {},
        onReEvaluateRules: () => {},
        onAutomate: () => {},
        onSplit: () => {},
        onUnsplit: vi.fn(),
    };
}

function buildColumns(statementPayments?: Map<string, CreditCardPayment>) {
    return createTransactionColumns({
        ...buildColumnsOptions(),
        statementPayments,
    });
}

describe('createTransactionColumns headings', () => {
    function Headings() {
        const table = useReactTable({
            data: [],
            columns: buildColumns(),
            getCoreRowModel: getCoreRowModel(),
            state: {
                columnVisibility: { creditor_name: true, debtor_name: true },
            },
        });

        return (
            <div>
                {table.getFlatHeaders().map((header) => (
                    <div key={header.id}>
                        {flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                        )}
                    </div>
                ))}
            </div>
        );
    }

    it('has no Date column: the day headers carry the date', () => {
        expect(
            buildColumns().some(
                (column) =>
                    column.id === 'transaction_date' ||
                    ('accessorKey' in column &&
                        column.accessorKey === 'transaction_date'),
            ),
        ).toBe(false);
    });

    it('labels every column without a control to sort by it', () => {
        render(<Headings />);

        expect(screen.getByText('Category')).toBeInTheDocument();
        expect(screen.getByText('Creditor')).toBeInTheDocument();
        expect(screen.getByText('Debtor')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('pads the Category column as the first one after the checkbox', () => {
        expect(categoryClassName(buildColumns())).toContain('pl-2');
    });
});

describe('createTransactionColumns amount', () => {
    it('shows the amount in the currency the transaction was made in', () => {
        const amount = buildColumns().find(
            (column) =>
                'accessorKey' in column && column.accessorKey === 'amount',
        );

        const transaction = {
            amount: -1200,
            // The account is in euros; the row is not, and the table shows the
            // row as it stands rather than converting it.
            currency_code: 'USD',
        } as DecryptedTransaction;

        render(
            <PrivacyModeProvider>
                {(
                    amount?.cell as (
                        context: CellContext<DecryptedTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: {
                        getValue: () => transaction.amount,
                        original: transaction,
                    },
                } as unknown as CellContext<DecryptedTransaction, unknown>)}
            </PrivacyModeProvider>,
        );

        expect(screen.getByText(/\$/)).toBeInTheDocument();
        expect(screen.queryByText(/€/)).not.toBeInTheDocument();
    });

    it('draws a row whose stored currency is not a code Intl takes', () => {
        const amount = buildColumns(false).find(
            (column) =>
                'accessorKey' in column && column.accessorKey === 'amount',
        );

        const transaction = {
            amount: -1200,
            currency_code: 'N/A',
        } as DecryptedTransaction;

        render(
            <PrivacyModeProvider>
                {(
                    amount?.cell as (
                        context: CellContext<DecryptedTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: {
                        getValue: () => transaction.amount,
                        original: transaction,
                    },
                } as unknown as CellContext<DecryptedTransaction, unknown>)}
            </PrivacyModeProvider>,
        );

        expect(screen.getByText(/12\.00.*N\/A/)).toBeInTheDocument();
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
        transaction: DecryptedTransaction,
        statementPayments?: Map<string, CreditCardPayment>,
    ) {
        const description = buildColumns(statementPayments).find(
            (column) =>
                'accessorKey' in column &&
                column.accessorKey === 'decryptedDescription',
        );

        render(
            <PrivacyModeProvider>
                {(
                    description?.cell as (
                        context: CellContext<DecryptedTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: { original: transaction },
                    table: { getState: () => ({ columnVisibility: {} }) },
                } as unknown as CellContext<DecryptedTransaction, unknown>)}
            </PrivacyModeProvider>,
        );
    }

    const row = (id: string) =>
        ({
            id,
            description: 'Merpag Débito -> BBVA Crédito',
            label_ids: [],
            notes: null,
        }) as unknown as DecryptedTransaction;

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

    it('notes it on each part of a split repayment, whose payment points at the original', () => {
        renderDescription(
            {
                ...row('part-1'),
                split_parent_id: 'payment-1',
            } as DecryptedTransaction,
            new Map([[payment.transaction_id, payment]]),
        );

        expect(
            screen.getByTestId('statement-payment-note'),
        ).toBeInTheDocument();
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
        const select = buildColumns().find((column) => column.id === 'select');
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

    // Narrow lists switch to cards, which select from their category circle,
    // so the table's checkbox has no phone-sized variant any more.
    it('draws the same plain checkbox in the heading and the rows', () => {
        const { header, row } = renderSelectCheckboxes();

        expect(row.className).not.toContain('max-md:');
        expect(header.className).toBe(row.className);
    });
});

describe('createTransactionColumns split parts', () => {
    function renderDescription(transaction: DecryptedTransaction) {
        const description = buildColumns().find(
            (column) =>
                'accessorKey' in column &&
                column.accessorKey === 'decryptedDescription',
        );

        render(
            <PrivacyModeProvider>
                {(
                    description?.cell as (
                        context: CellContext<DecryptedTransaction, unknown>,
                    ) => React.ReactNode
                )?.({
                    row: { original: transaction },
                    table: { getState: () => ({ columnVisibility: {} }) },
                } as unknown as CellContext<DecryptedTransaction, unknown>)}
            </PrivacyModeProvider>,
        );
    }

    const whole = {
        id: 'tx-1',
        description: 'MERCADONA S.A.',
        label_ids: [],
        notes: null,
        currency_code: 'EUR',
    } as unknown as DecryptedTransaction;

    it('marks a part of a split beside its description', () => {
        renderDescription({ ...whole, split_parent_id: 'tx-0' });

        expect(
            screen.getByRole('button', {
                name: 'This transaction is part of a split',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('MERCADONA S.A.')).toBeInTheDocument();
    });

    it('leaves a whole transaction unmarked', () => {
        renderDescription(whole);

        expect(screen.queryByTestId('split-origin')).not.toBeInTheDocument();
    });
});
