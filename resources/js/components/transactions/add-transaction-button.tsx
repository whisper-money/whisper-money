import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { type SharedData } from '@/types';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';

/**
 * Adding a transaction by hand from anywhere in the app. A quarter of active
 * users never connect a bank, so this is their only way in and it cannot
 * depend on which screen they happen to be on.
 */
export function AddTransactionButton() {
    const { hasTransactionalAccounts } = usePage<SharedData>().props;
    const { data, loading, load } = useTransactionDialogData();
    const [open, setOpen] = useState(false);
    // Set only by the toast's "Change category", which reopens the same dialog
    // on the transaction a rule just filed away out of sight.
    const [editing, setEditing] = useState<DecryptedTransaction | null>(null);
    // A ref, not state: the dialog reports the save and closes in the same
    // batch, so a state flag would still read false in the close handler.
    const savedSomething = useRef(false);

    const isDisabled = !hasTransactionalAccounts;

    async function handleOpen() {
        if (isDisabled || loading) {
            return;
        }

        if (await load()) {
            setOpen(true);
        }
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

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            className={`h-9 w-9 px-0 has-[>svg]:px-0 md:w-auto md:px-4 md:has-[>svg]:px-3 ${isDisabled || loading ? 'cursor-not-allowed opacity-50' : ''}`}
                            onClick={handleOpen}
                            aria-disabled={isDisabled || loading}
                            aria-label={__('Add transaction')}
                            data-testid="add-transaction-button"
                        >
                            <Plus className="h-5 w-5" />
                            <span className="hidden md:inline">
                                {__('Transaction')}
                            </span>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {isDisabled
                            ? __('You need an account to record transactions')
                            : __('Create a new transaction')}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            {data && (
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
                    origin="quick_add"
                    onRequestEdit={(created) => {
                        setEditing(created);
                        setOpen(true);
                    }}
                />
            )}
        </>
    );
}
