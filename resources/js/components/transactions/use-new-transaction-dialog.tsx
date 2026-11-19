import {
    EditTransactionDialog,
    type TransactionCreateOrigin,
} from '@/components/transactions/edit-transaction-dialog';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { filterTransactionalAccounts } from '@/types/account';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { type ReactNode, useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * The app's "new transaction" dialog, opened on demand: its lists are fetched
 * the first time it opens, and the page underneath is refreshed once a save
 * has closed it. `dialog` must be rendered for `show` to have anything to open.
 */
export function useNewTransactionDialog(origin: TransactionCreateOrigin): {
    /**
     * Opens the dialog, on `accountId` when it is an account the user can
     * file transactions in (otherwise on the dialog's usual default).
     * Resolves to whether it opened.
     */
    show: (accountId?: string | null) => Promise<boolean>;
    loading: boolean;
    dialog: ReactNode;
} {
    const { data, loading, load } = useTransactionDialogData();
    const [open, setOpen] = useState(false);
    const [initialAccountId, setInitialAccountId] = useState<string | null>(
        null,
    );
    // Set only by the toast's "Change category", which reopens the same dialog
    // on the transaction a rule just filed away out of sight.
    const [editing, setEditing] = useState<ServerTransaction | null>(null);
    // A ref, not state: the dialog reports the save and closes in the same
    // batch, so a state flag would still read false in the close handler.
    const savedSomething = useRef(false);

    async function show(accountId: string | null = null): Promise<boolean> {
        if (loading) {
            return false;
        }

        const loaded = await load();

        if (!loaded) {
            return false;
        }

        const accounts = filterTransactionalAccounts(loaded.accounts);

        if (accounts.length === 0) {
            toast.error(__('You need an account to record transactions'));

            return false;
        }

        setInitialAccountId(
            accounts.some((account) => account.id === accountId)
                ? accountId
                : null,
        );
        setOpen(true);

        return true;
    }

    function handleOpenChange(next: boolean) {
        setOpen(next);

        if (next) {
            return;
        }

        setEditing(null);

        // The page underneath was rendered before any of this existed, so it
        // only learns about the new rows once the dialog is out of the way.
        if (savedSomething.current) {
            savedSomething.current = false;
            refreshPageAfterWrite();
        }
    }

    const dialog = data && (
        <EditTransactionDialog
            transaction={editing}
            categories={data.categories}
            accounts={data.accounts}
            banks={data.banks}
            labels={data.labels}
            automationRules={data.automationRules}
            open={open}
            onOpenChange={handleOpenChange}
            onSuccess={() => {
                savedSomething.current = true;
            }}
            mode={editing ? 'edit' : 'create'}
            origin={origin}
            initialAccountId={initialAccountId}
            onRequestEdit={(created) => {
                setEditing(created);
                setOpen(true);
            }}
        />
    );

    return { show, loading, dialog };
}
