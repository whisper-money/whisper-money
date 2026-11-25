import { transactionSyncService } from '@/services/transaction-sync';
import { type DecryptedTransaction } from '@/types/transaction';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UnsplitTransactionDialog } from './unsplit-transaction-dialog';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { unsplit: vi.fn(async () => ({})) },
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

// A split's parts as the list ships them: each one names all of them.
const part = {
    id: 'part-1',
    description: 'HARDWARE STORE',
    description_iv: null,
    decryptedDescription: 'HARDWARE STORE',
    amount: -4000,
    currency_code: 'EUR',
    transaction_date: '2026-10-01',
    split_parent_id: 'original',
    split_siblings: [
        {
            id: 'part-1',
            split_parent_id: 'original',
            amount: -4000,
            category_id: null,
        },
        {
            id: 'part-2',
            split_parent_id: 'original',
            amount: -2000,
            category_id: null,
        },
    ],
} as DecryptedTransaction;

function renderDialog(transaction: DecryptedTransaction) {
    const onOpenChange = vi.fn();
    const onSuccess = vi.fn();

    render(
        <UnsplitTransactionDialog
            transaction={transaction}
            categories={[]}
            onOpenChange={onOpenChange}
            onSuccess={onSuccess}
        />,
    );

    return { onOpenChange, onSuccess };
}

describe('UnsplitTransactionDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('merges the split back through the part, evicting every sibling', async () => {
        const { onOpenChange, onSuccess } = renderDialog(part);

        expect(screen.getByText('Merge the 2 splits back?')).toBeTruthy();
        expect(
            screen.getByText(
                'HARDWARE STORE goes back to being a single transaction of -€60.00.',
            ),
        ).toBeTruthy();

        fireEvent.click(screen.getByTestId('confirm-unsplit'));

        await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
        expect(transactionSyncService.unsplit).toHaveBeenCalledWith('part-1', [
            'part-1',
            'part-2',
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('still merges a part that arrived without its siblings', async () => {
        const { onSuccess } = renderDialog({
            ...part,
            split_siblings: undefined,
        });

        // Nothing to list or add up, so it only asks.
        expect(screen.getByText('Merge the split back?')).toBeTruthy();
        expect(screen.queryByText('These splits are deleted')).toBeNull();

        fireEvent.click(screen.getByTestId('confirm-unsplit'));

        await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
        expect(transactionSyncService.unsplit).toHaveBeenCalledWith(
            'part-1',
            [],
        );
    });

    it('stays open and says so when the merge fails', async () => {
        vi.mocked(transactionSyncService.unsplit).mockRejectedValueOnce(
            new Error('network'),
        );
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const { onOpenChange, onSuccess } = renderDialog(part);

        fireEvent.click(screen.getByTestId('confirm-unsplit'));

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                'The split could not be merged back.',
            ),
        );
        expect(onOpenChange).not.toHaveBeenCalled();
        expect(onSuccess).not.toHaveBeenCalled();
    });
});
