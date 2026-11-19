import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WidgetDialogs, { type WidgetDialogAsk } from './widget-dialogs';

const { refreshPageAfterWrite, toastError } = vi.hoisted(() => ({
    refreshPageAfterWrite: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@/lib/refresh-page', () => ({ refreshPageAfterWrite }));
vi.mock('sonner', () => ({ toast: { error: toastError } }));

const CASH = '0198f0a1-7c3e-7a10-9b2c-1d2e3f4a5b6c';
const CARD = '0198f0a1-7c3e-7a10-9b2c-6c5b4a3f2e1d';
const BROKER = '0198f0a1-7c3e-7a10-9b2c-aaaaaaaaaaaa';
const NOT_MINE = '0198f0a1-7c3e-7a10-9b2c-bbbbbbbbbbbb';

const dialogData = vi.hoisted(() => ({
    current: {
        accounts: [] as { id: string; type: string; archived_at: null }[],
        categories: [],
        banks: [],
        labels: [],
        automationRules: [],
    },
}));
const load = vi.fn(async () => dialogData.current);

vi.mock('@/hooks/use-transaction-dialog-data', () => ({
    useTransactionDialogData: () => ({
        data: dialogData.current,
        loading: false,
        load,
    }),
}));

vi.mock('@/components/transactions/edit-transaction-dialog', () => ({
    EditTransactionDialog: ({
        open,
        initialAccountId,
        origin,
        onSuccess,
        onOpenChange,
    }: {
        open: boolean;
        initialAccountId: string | null;
        origin: string;
        onSuccess: (transaction: unknown) => void;
        onOpenChange: (open: boolean) => void;
    }) =>
        open ? (
            <div
                data-testid="create-dialog"
                data-account={initialAccountId ?? 'default'}
                data-origin={origin}
            >
                <button
                    onClick={() => {
                        onSuccess({ id: 'tx-1' });
                        onOpenChange(false);
                    }}
                >
                    Save
                </button>
                <button onClick={() => onOpenChange(false)}>Cancel</button>
            </div>
        ) : null,
}));

vi.mock('@/components/transactions/import-transactions-drawer', () => ({
    ImportTransactionsDrawer: ({
        open,
        initialAccountId,
        onImportComplete,
        onOpenChange,
    }: {
        open: boolean;
        initialAccountId: string | null;
        onImportComplete: (count: number) => void;
        onOpenChange: (open: boolean) => void;
    }) =>
        open ? (
            <div
                data-testid="import-drawer"
                data-account={initialAccountId ?? 'default'}
            >
                <button
                    onClick={() => {
                        // What a clean import does: close, then report.
                        onOpenChange(false);
                        onImportComplete(3);
                    }}
                >
                    Import all
                </button>
                <button onClick={() => onImportComplete(2)}>Import some</button>
                <button onClick={() => onOpenChange(false)}>Close</button>
            </div>
        ) : null,
}));

function ask(
    id: number,
    dialog: WidgetDialogAsk['request']['dialog'],
    accountId: string | null,
): WidgetDialogAsk {
    return { id, request: { dialog, accountId } };
}

describe('<WidgetDialogs>', () => {
    beforeEach(() => {
        load.mockClear();
        refreshPageAfterWrite.mockClear();
        toastError.mockClear();
        dialogData.current = {
            ...dialogData.current,
            accounts: [
                { id: CASH, type: 'cash', archived_at: null },
                { id: CARD, type: 'credit_card', archived_at: null },
                { id: BROKER, type: 'investment', archived_at: null },
            ],
        };
    });

    it('opens the new-transaction dialog on the account the widget named', async () => {
        render(<WidgetDialogs ask={ask(1, 'newTransaction', CASH)} />);

        const dialog = await screen.findByTestId('create-dialog');

        expect(load).toHaveBeenCalledTimes(1);
        expect(dialog.dataset.account).toBe(CASH);
        expect(dialog.dataset.origin).toBe('widget');
    });

    it.each([
        ['another user', NOT_MINE],
        ['an investment account', BROKER],
        ['no account', null],
    ])('opens on its usual default when given %s', async (_name, accountId) => {
        render(<WidgetDialogs ask={ask(1, 'newTransaction', accountId)} />);

        expect(
            (await screen.findByTestId('create-dialog')).dataset.account,
        ).toBe('default');
    });

    it('reloads the dashboard once a save has closed the dialog', async () => {
        render(<WidgetDialogs ask={ask(1, 'newTransaction', CASH)} />);

        fireEvent.click(await screen.findByText('Save'));

        expect(refreshPageAfterWrite).toHaveBeenCalledTimes(1);
        expect(screen.queryByTestId('create-dialog')).toBeNull();
    });

    it('writes and reloads nothing when the user cancels', async () => {
        render(<WidgetDialogs ask={ask(1, 'newTransaction', CASH)} />);

        fireEvent.click(await screen.findByText('Cancel'));

        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    it('says why when there is no account to file one in', async () => {
        dialogData.current = { ...dialogData.current, accounts: [] };

        render(<WidgetDialogs ask={ask(1, 'newTransaction', CASH)} />);

        await act(async () => {});

        expect(screen.queryByTestId('create-dialog')).toBeNull();
        expect(toastError).toHaveBeenCalledWith(
            'You need an account to record transactions',
        );
    });

    it('opens the importer on the card the widget named', async () => {
        render(<WidgetDialogs ask={ask(1, 'import', CARD)} />);

        expect(
            (await screen.findByTestId('import-drawer')).dataset.account,
        ).toBe(CARD);
        expect(screen.queryByTestId('create-dialog')).toBeNull();
    });

    it('reloads after an import that closed the drawer', async () => {
        render(<WidgetDialogs ask={ask(1, 'import', CARD)} />);

        fireEvent.click(await screen.findByText('Import all'));

        expect(refreshPageAfterWrite).toHaveBeenCalledTimes(1);
    });

    it('keeps a partial import on screen, and reloads once it is closed', async () => {
        render(<WidgetDialogs ask={ask(1, 'import', CARD)} />);

        fireEvent.click(await screen.findByText('Import some'));

        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
        expect(screen.getByTestId('import-drawer')).toBeTruthy();

        fireEvent.click(screen.getByText('Close'));

        expect(refreshPageAfterWrite).toHaveBeenCalledTimes(1);
    });

    it('closing the importer without importing reloads nothing', async () => {
        render(<WidgetDialogs ask={ask(1, 'import', null)} />);

        const drawer = await screen.findByTestId('import-drawer');

        expect(drawer.dataset.account).toBe('default');

        fireEvent.click(screen.getByText('Close'));

        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    it('opens again on each new ask, on its own account', async () => {
        const { rerender } = render(
            <WidgetDialogs ask={ask(1, 'newTransaction', CASH)} />,
        );

        fireEvent.click(await screen.findByText('Cancel'));
        rerender(<WidgetDialogs ask={ask(2, 'newTransaction', CARD)} />);

        expect(
            (await screen.findByTestId('create-dialog')).dataset.account,
        ).toBe(CARD);
        expect(load).toHaveBeenCalledTimes(2);
    });
});
