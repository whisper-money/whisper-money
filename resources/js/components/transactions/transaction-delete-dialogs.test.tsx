import { type TransactionDeleteDialogState } from '@/hooks/use-transaction-bulk-actions';
import { type ServerTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TransactionDeleteDialogs } from './transaction-delete-dialogs';

function dialogState(
    overrides: Partial<TransactionDeleteDialogState> = {},
): TransactionDeleteDialogState {
    return {
        transaction: { id: 'a' } as ServerTransaction,
        isBulk: false,
        selectedCount: 0,
        touchesManualAccount: false,
        updateBalance: true,
        setUpdateBalance: vi.fn(),
        isDeleting: false,
        onConfirm: vi.fn(),
        onClose: vi.fn(),
        ...overrides,
    };
}

describe('TransactionDeleteDialogs', () => {
    it('confirms one transaction with a plain alert', () => {
        const dialog = dialogState();
        render(<TransactionDeleteDialogs dialog={dialog} />);

        expect(screen.getByText('Delete Transaction')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(dialog.onConfirm).toHaveBeenCalledOnce();
    });

    it('offers to move a manual balance only when one is affected', () => {
        const { rerender } = render(
            <TransactionDeleteDialogs dialog={dialogState()} />,
        );
        expect(screen.queryByRole('checkbox')).toBeNull();

        rerender(
            <TransactionDeleteDialogs
                dialog={dialogState({ touchesManualAccount: true })}
            />,
        );
        expect(screen.getByRole('checkbox')).toBeTruthy();
    });

    it('confirms several transactions with a plain alert by default', () => {
        render(
            <TransactionDeleteDialogs
                dialog={dialogState({ isBulk: true, selectedCount: 3 })}
            />,
        );

        expect(
            screen.getByText(
                'Are you sure you want to delete 3 transactions? This action cannot be undone.',
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('textbox')).toBeNull();
    });

    it('makes the user type the count when asked to', () => {
        const dialog = dialogState({ isBulk: true, selectedCount: 3 });
        render(
            <TransactionDeleteDialogs dialog={dialog} confirmBulkByTyping />,
        );

        const confirm = screen.getByRole('button', { name: 'Delete' });
        expect((confirm as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Delete 3 Transactions' },
        });
        fireEvent.click(confirm);

        expect(dialog.onConfirm).toHaveBeenCalledOnce();
    });

    it('starts the typed confirmation empty the next time it opens', () => {
        const open = dialogState({ isBulk: true, selectedCount: 3 });
        const { rerender } = render(
            <TransactionDeleteDialogs dialog={open} confirmBulkByTyping />,
        );
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Delete 3' },
        });

        rerender(
            <TransactionDeleteDialogs
                dialog={dialogState({ transaction: null, isBulk: true })}
                confirmBulkByTyping
            />,
        );
        rerender(
            <TransactionDeleteDialogs dialog={open} confirmBulkByTyping />,
        );

        expect((screen.getByRole('textbox') as HTMLInputElement).value).toBe(
            '',
        );
    });
});
