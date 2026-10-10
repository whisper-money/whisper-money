import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AddTransactionButton } from './add-transaction-button';

/**
 * The add-transaction button in the app header, for the readers who add a
 * meaningful share of their transactions by hand, so they can do it from any
 * screen. Outlined rather than primary: it sits next to the import button as
 * a tool, not as the page's main action. Everyone else adds from the bar above
 * the transactions list.
 */
export function HeaderAddTransactionButton() {
    const { showHeaderAddTransaction } = usePage<SharedData>().props;

    if (!showHeaderAddTransaction) {
        return null;
    }

    return (
        <AddTransactionButton
            // The shared flag is only on while the reader owns an account
            // that can hold one.
            hasTransactionalAccounts
            variant="outline"
            origin="header"
            collapse="viewport"
            testId="header-add-transaction-button"
        />
    );
}
