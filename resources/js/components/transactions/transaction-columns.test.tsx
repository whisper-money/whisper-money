import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import type { DecryptedTransaction } from '@/types/transaction';
import type { CellContext, ColumnDef } from '@tanstack/react-table';
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

function buildColumns(isDateHidden: boolean) {
    return createTransactionColumns({
        categories: [],
        accounts: [],
        banks: [],
        labels: [],
        locale: 'en',
        onEdit: () => {},
        onDelete: () => {},
        onUpdate: () => {},
        onReEvaluateRules: () => {},
        onSplit: () => {},
        onUnsplit: () => {},
        isDateHidden,
    });
}

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
});
