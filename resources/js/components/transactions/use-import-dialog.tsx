import { ImportTransactionsDrawer } from '@/components/transactions/import-transactions-drawer';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { transactionalAccountId } from '@/types/account';
import { type ReactNode, useRef, useState } from 'react';

/**
 * The importer drawer, opened on demand: `show` opens it on `accountId` when
 * the user can file transactions there, and `dialog` must be rendered for it
 * to have anything to open. Unlike the header's, which stays mounted across
 * pages, it lives on the page it reloads: the reload waits for
 * the drawer to close, or a partial import would lose the failures it lists.
 */
export function useImportDialog(): {
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

        // The drawer's own account step lists the same accounts.
        setInitialAccountId(transactionalAccountId(loaded.accounts, accountId));
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
