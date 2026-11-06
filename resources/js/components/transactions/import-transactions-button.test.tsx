import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsButton } from './import-transactions-button';

const { load, flash, captureEvent } = vi.hoisted(() => ({
    load: vi.fn(),
    flash: { current: {} as Record<string, unknown> },
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: flash.current } }),
}));
vi.mock('@/hooks/use-transaction-dialog-data', () => ({
    useTransactionDialogData: () => ({
        data: { accounts: [], categories: [], banks: [], automationRules: [] },
        loading: false,
        load,
    }),
}));
// The drawer is covered on its own; here only what it is opened with matters.
vi.mock('./import-transactions-drawer', () => ({
    ImportTransactionsDrawer: ({
        open,
        initialAccountId,
    }: {
        open: boolean;
        initialAccountId: string | null;
    }) => (
        <div data-testid="drawer" data-open={open}>
            {initialAccountId}
        </div>
    ),
}));

describe('ImportTransactionsButton', () => {
    beforeEach(() => {
        load.mockReset().mockResolvedValue(true);
        captureEvent.mockReset();
        flash.current = {};
    });

    it('opens the importer by itself on the account an emailed link names', async () => {
        flash.current = { import_link_account_id: 'account-1' };

        render(<ImportTransactionsButton />);

        await waitFor(() =>
            expect(screen.getByTestId('drawer')).toHaveAttribute(
                'data-open',
                'true',
            ),
        );
        expect(screen.getByTestId('drawer')).toHaveTextContent('account-1');
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_import_link_opened',
            { landing: 'importer' },
        );
    });

    it('stays closed on an ordinary visit', () => {
        render(<ImportTransactionsButton />);

        expect(load).not.toHaveBeenCalled();
        expect(screen.getByTestId('drawer')).toHaveAttribute(
            'data-open',
            'false',
        );
    });
});
