import type { DecryptedTransaction } from '@/types/transaction';
import type { ColumnDef } from '@tanstack/react-table';
import { describe, expect, it } from 'vitest';
import { createTransactionColumns } from './transaction-columns';

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
