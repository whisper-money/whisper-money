import { ImportTransactionsDrawer } from '@/components/transactions/import-transactions-drawer';
import { useNewTransactionDialog } from '@/components/transactions/use-new-transaction-dialog';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { filterTransactionalAccounts } from '@/types/account';
import { type WidgetDialogRequest } from '@/widgets/sdk/open-values';
import { type ReactNode, useEffect, useRef, useState } from 'react';

/** A widget's `wm.open()` asking for a dialog; `id` tells two asks apart. */
export interface WidgetDialogAsk {
    id: number;
    request: WidgetDialogRequest;
}

/**
 * The app's dialogs a user widget can open over the dashboard with
 * `wm.open({ newTransaction })` and `wm.open({ import })`: the very dialog of
 * "+ Transaction" and the importer of the header, on the account the widget
 * named when the user can file transactions in it (otherwise on their usual
 * default). Nothing is written until the user confirms there; once a save or
 * an import has closed them, the page (its widgets' data included) reloads.
 */
export default function WidgetDialogs({ ask }: { ask: WidgetDialogAsk }) {
    const newTransaction = useNewTransactionDialog('widget');
    const importer = useImportDialog();
    const { show: showNewTransaction } = newTransaction;
    const { show: showImporter } = importer;

    useEffect(() => {
        const { dialog, accountId } = ask.request;

        void (dialog === 'newTransaction'
            ? showNewTransaction(accountId)
            : showImporter(accountId));
        // Once per ask: the shows change identity every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ask.id]);

    return (
        <>
            {newTransaction.dialog}
            {importer.dialog}
        </>
    );
}

/**
 * The importer drawer, opened on demand. Unlike the header's, which stays
 * mounted across pages, it lives on the page it reloads: the reload waits for
 * the drawer to close, or a partial import would lose the failures it lists.
 */
function useImportDialog(): {
    show: (accountId: string | null) => Promise<boolean>;
    dialog: ReactNode;
} {
    const { data, load } = useTransactionDialogData();
    const [open, setOpen] = useState(false);
    const [initialAccountId, setInitialAccountId] = useState<string | null>(
        null,
    );
    const importedSomething = useRef(false);
    // A ref as well: a full import closes the drawer before it reports the
    // count, in the same handler.
    const isOpen = useRef(false);

    async function show(accountId: string | null): Promise<boolean> {
        const loaded = await load();

        if (!loaded) {
            return false;
        }

        setInitialAccountId(
            filterTransactionalAccounts(loaded.accounts).some(
                (account) => account.id === accountId,
            )
                ? accountId
                : null,
        );
        isOpen.current = true;
        setOpen(true);

        return true;
    }

    function refreshOnceClosed() {
        if (!isOpen.current && importedSomething.current) {
            importedSomething.current = false;
            refreshPageAfterWrite();
        }
    }

    function handleOpenChange(next: boolean) {
        isOpen.current = next;
        setOpen(next);
        refreshOnceClosed();
    }

    const dialog = data && (
        <ImportTransactionsDrawer
            open={open}
            onOpenChange={handleOpenChange}
            initialAccountId={initialAccountId}
            onImportComplete={() => {
                importedSomething.current = true;
                refreshOnceClosed();
            }}
            accounts={data.accounts}
            categories={data.categories}
            banks={data.banks}
            automationRules={data.automationRules}
        />
    );

    return { show, dialog };
}
