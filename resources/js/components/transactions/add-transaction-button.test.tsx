import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AddTransactionButton } from './add-transaction-button';

let hasTransactionalAccounts = true;

// Hoisted: the mock factory reads it while the module graph is still loading.
const { refreshPageAfterWrite } = vi.hoisted(() => ({
    refreshPageAfterWrite: vi.fn(),
}));

vi.mock('@/lib/refresh-page', () => ({ refreshPageAfterWrite }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { hasTransactionalAccounts } }),
}));

const load = vi.fn(async () => true);

vi.mock('@/hooks/use-transaction-dialog-data', () => ({
    useTransactionDialogData: () => ({
        data: {
            accounts: [],
            categories: [],
            banks: [],
            labels: [],
            automationRules: [],
        },
        loading: false,
        load,
    }),
}));

vi.mock('./edit-transaction-dialog', () => ({
    EditTransactionDialog: ({
        open,
        onSuccess,
        onOpenChange,
    }: {
        open: boolean;
        onSuccess: (transaction: unknown) => void;
        onOpenChange: (open: boolean) => void;
    }) =>
        open ? (
            <button
                data-testid="create-dialog"
                onClick={() => {
                    // What the real dialog does on a save: report it, then
                    // close, both in the same batch.
                    onSuccess({ id: 'tx-1' });
                    onOpenChange(false);
                }}
            />
        ) : null,
}));

describe('AddTransactionButton', () => {
    beforeEach(() => {
        hasTransactionalAccounts = true;
        load.mockClear();
        refreshPageAfterWrite.mockClear();
    });

    it('opens the create dialog once the lists are loaded', async () => {
        render(<AddTransactionButton />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));

        expect(load).toHaveBeenCalled();
        await waitFor(() => {
            expect(screen.getByTestId('create-dialog')).toBeInTheDocument();
        });
    });

    it('refreshes the page underneath once a save has closed the dialog', async () => {
        render(<AddTransactionButton />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        const dialog = await screen.findByTestId('create-dialog');

        fireEvent.click(dialog);

        expect(refreshPageAfterWrite).toHaveBeenCalled();
    });

    it('leaves the page alone when the dialog closes with nothing saved', async () => {
        render(<AddTransactionButton />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        await screen.findByTestId('create-dialog');

        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    it('stays disabled while the user owns no account to file one in', () => {
        hasTransactionalAccounts = false;
        render(<AddTransactionButton />);

        const button = screen.getByTestId('add-transaction-button');
        expect(button).toHaveAttribute('aria-disabled', 'true');

        fireEvent.click(button);

        expect(load).not.toHaveBeenCalled();
        expect(screen.queryByTestId('create-dialog')).not.toBeInTheDocument();
    });
});
