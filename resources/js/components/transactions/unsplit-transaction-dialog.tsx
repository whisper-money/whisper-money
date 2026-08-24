import { SplitPartsList } from '@/components/transactions/split-parts-list';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { useLocale } from '@/hooks/use-locale';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Merge } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface UnsplitTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    onOpenChange: (open: boolean) => void;
    onSuccess: () => void;
}

/**
 * Merging a split back is not reversible by hand, so it says exactly what goes
 * away: the parts, and everything the user set on them.
 */
export function UnsplitTransactionDialog({
    transaction,
    categories,
    onOpenChange,
    onSuccess,
}: UnsplitTransactionDialogProps) {
    const locale = useLocale();
    const [isMerging, setIsMerging] = useState(false);

    const siblings = transaction?.split_siblings ?? [];
    const originalAmount = siblings.reduce(
        (total, sibling) => total + sibling.amount,
        0,
    );

    const handleMerge = async () => {
        if (!transaction || isMerging) {
            return;
        }

        setIsMerging(true);

        try {
            await transactionSyncService.unsplit(transaction.id);

            toast.success(__('The split was merged back.'));
            onOpenChange(false);
            onSuccess();
        } catch (error) {
            console.error('Failed to merge the split back:', error);
            toast.error(__('The split could not be merged back.'));
        } finally {
            setIsMerging(false);
        }
    };

    return (
        <AlertDialog
            open={transaction !== null}
            onOpenChange={(open) => !open && onOpenChange(false)}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {__('Merge the :count splits back?', {
                            count: siblings.length,
                        })}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {transaction === null
                            ? null
                            : __(
                                  ':description goes back to being a single transaction of :amount.',
                                  {
                                      description:
                                          transaction.decryptedDescription,
                                      amount: formatCurrency(
                                          originalAmount,
                                          transaction.currency_code,
                                          locale,
                                      ),
                                  },
                              )}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {siblings.length > 0 && transaction !== null && (
                    <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/40 p-3 text-sm">
                        <p className="text-xs text-muted-foreground">
                            {__('These splits are deleted')}
                        </p>
                        <SplitPartsList
                            parts={siblings}
                            categories={categories}
                            currencyCode={transaction.currency_code}
                            rowClassName="line-through opacity-60"
                        />
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    {__(
                        'The category, labels and notes of each split are lost, and the original comes back with the category it had before. This cannot be undone.',
                    )}
                </p>

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isMerging}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            event.preventDefault();
                            handleMerge();
                        }}
                        disabled={isMerging}
                    >
                        <Merge />
                        {isMerging ? __('Merging...') : __('Merge')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
