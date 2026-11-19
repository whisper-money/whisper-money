import { type TransactionCreateOrigin } from '@/components/transactions/edit-transaction-dialog';
import { useNewTransactionDialog } from '@/components/transactions/use-new-transaction-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ICON_ONLY_BELOW_SM } from '@/lib/icon-only-below-sm';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Plus } from 'lucide-react';

/**
 * "+ Transaction", the face of every add-transaction button above a
 * transactions list. A collapsible label drops to the icon alone below `sm`;
 * the accessible name stays on the button's aria-label.
 */
export function AddTransactionLabel({
    collapsible = false,
}: {
    collapsible?: boolean;
}) {
    return (
        <>
            <Plus className="h-4 w-4" />
            <span
                className={collapsible ? ICON_ONLY_BELOW_SM.label : undefined}
            >
                {__('Transaction')}
            </span>
        </>
    );
}

interface AddTransactionButtonProps {
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    variant?: 'default' | 'outline';
    /** Which surface the button sits on, for `transaction_created`. */
    origin?: TransactionCreateOrigin;
    testId?: string;
    className?: string;
}

/**
 * Adding a transaction by hand, from right above the transactions list so
 * what was just entered can be checked without scrolling back up. A quarter
 * of active users never connect a bank, so this is their only way in.
 */
export function AddTransactionButton({
    hasTransactionalAccounts,
    variant = 'default',
    origin = 'quick_add',
    testId = 'add-transaction-button',
    className,
}: AddTransactionButtonProps) {
    const { show, loading, dialog } = useNewTransactionDialog(origin);

    const isDisabled = !hasTransactionalAccounts;

    function handleOpen() {
        if (!isDisabled) {
            void show();
        }
    }

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant={variant}
                            className={cn(
                                ICON_ONLY_BELOW_SM.button,
                                (isDisabled || loading) &&
                                    'cursor-not-allowed opacity-50',
                                className,
                            )}
                            onClick={handleOpen}
                            aria-disabled={isDisabled || loading}
                            aria-label={__('Add transaction')}
                            data-testid={testId}
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

            {dialog}
        </>
    );
}
