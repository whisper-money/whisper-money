import { type ImportHistoryEntry } from '@/types/full-import';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UndoImportDialog } from './undo-import-dialog';

const routerDelete = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { delete: routerDelete },
}));

const entry: ImportHistoryEntry = {
    id: 'import-1',
    source: 'banktrack',
    mode: 'add',
    status: 'completed',
    file_name: 'banktrack-export.csv',
    error: null,
    created_at: '2026-10-03T10:00:00+00:00',
    finished_at: null,
    undone_at: null,
    stats: {},
    undoable: true,
    summary: {
        accounts: [{ name: 'Wise', transactions: 12 }],
        categories: 2,
        transactions: 15,
        balances: 4,
        later_transactions: 3,
        into_own_accounts: [{ name: 'BBVA Conjunta', transactions: 1 }],
    },
};

function renderDialog(onOpenChange = vi.fn()) {
    render(
        <UndoImportDialog
            entry={entry}
            open
            onOpenChange={onOpenChange}
            locale="en-US"
        />,
    );

    return onOpenChange;
}

describe('UndoImportDialog', () => {
    beforeEach(() => routerDelete.mockReset());

    it('lists what goes, including what was added afterwards', () => {
        renderDialog();

        expect(screen.getByText('1 account: Wise')).toBeInTheDocument();
        expect(screen.getByText('2 new categories')).toBeInTheDocument();
        expect(
            screen.getByText('15 transactions and 4 daily balances'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'BBVA Conjunta stays, without the imported transaction.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '3 transactions added to these accounts afterwards (by hand or by another import) are deleted too.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Subcategories you created under the imported categories are removed too.',
            ),
        ).toBeInTheDocument();
    });

    it('stays open while undoing and closes once it succeeds', () => {
        const onOpenChange = renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Undo import' }));

        expect(onOpenChange).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /Undoing/ })).toBeDisabled();

        const [, options] = routerDelete.mock.calls[0];
        act(() => {
            options.onSuccess();
            options.onFinish();
        });

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('shows a failure inside the dialog', () => {
        const onOpenChange = renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Undo import' }));

        const [, options] = routerDelete.mock.calls[0];
        act(() => {
            options.onError({ import: 'This import cannot be undone.' });
            options.onFinish();
        });

        expect(
            screen.getByText('This import cannot be undone.'),
        ).toBeInTheDocument();
        expect(onOpenChange).not.toHaveBeenCalled();
    });
});
