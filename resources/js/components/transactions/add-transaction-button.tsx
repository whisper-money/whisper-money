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
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';

/**
 * Squares the button around its icon while the label is hidden. The bar it
 * sits in is the size container (see TransactionFilters' actions slot), so
 * this follows the room actually left beside the sidebar, not the viewport.
 */
const COLLAPSIBLE_BUTTON_CLASSES =
    'w-9 px-0 has-[>svg]:px-0 @xs:w-auto @xs:px-4 @xs:has-[>svg]:px-3';

/**
 * "+ Transaction", the face of every add-transaction button above a
 * transactions list. A collapsible label drops to the icon alone when the
 * bar is too narrow to fit it next to the other actions (320px phones on
 * /transactions); the accessible name stays on the button's aria-label.
 */
export function AddTransactionLabel({
    collapsible = false,
}: {
    collapsible?: boolean;
}) {
    return (
        <>
            <Plus className="h-4 w-4" />
            <span className={collapsible ? 'hidden @xs:inline' : undefined}>
                {__('Transaction')}
            </span>
        </>
    );
}

interface AddTransactionButtonProps {
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
}

/**
 * Adding a transaction by hand, from right above the transactions list so
 * what was just entered can be checked without scrolling back up. A quarter
 * of active users never connect a bank, so this is their only way in.
 */
export function AddTransactionButton({
    hasTransactionalAccounts,
}: AddTransactionButtonProps) {
    const { data, loading, load } = useTransactionDialogData();
    const [open, setOpen] = useState(false);
    // Set only by the toast's "Change category", which reopens the same dialog
    // on the transaction a rule just filed away out of sight.
    const [editing, setEditing] = useState<ServerTransaction | null>(null);
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
                            className={`${COLLAPSIBLE_BUTTON_CLASSES} ${isDisabled || loading ? 'cursor-not-allowed opacity-50' : ''}`}
                            onClick={handleOpen}
                            aria-disabled={isDisabled || loading}
                            aria-label={__('Add transaction')}
                            data-testid="add-transaction-button"
                        >
                            <AddTransactionLabel collapsible />
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
