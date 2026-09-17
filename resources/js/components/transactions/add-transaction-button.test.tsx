import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AddTransactionButton } from './add-transaction-button';

let hasTransactionalAccounts = true;

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
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
    EditTransactionDialog: ({ open }: { open: boolean }) =>
        open ? <div data-testid="create-dialog" /> : null,
}));

describe('AddTransactionButton', () => {
    beforeEach(() => {
        hasTransactionalAccounts = true;
        load.mockClear();
    });

    it('opens the create dialog once the lists are loaded', async () => {
        render(<AddTransactionButton />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));

        expect(load).toHaveBeenCalled();
        await waitFor(() => {
            expect(screen.getByTestId('create-dialog')).toBeInTheDocument();
        });
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
