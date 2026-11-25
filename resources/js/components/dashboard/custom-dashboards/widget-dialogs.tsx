import { useImportDialog } from '@/components/transactions/use-import-dialog';
import { useNewTransactionDialog } from '@/components/transactions/use-new-transaction-dialog';
import { type WidgetDialogRequest } from '@/widgets/sdk/open-values';
import { useEffect } from 'react';

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
