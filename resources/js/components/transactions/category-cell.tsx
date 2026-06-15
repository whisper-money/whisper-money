import { AiSparkleIcon } from '@/components/transactions/ai-sparkle-icon';
import { CategorySelect } from '@/components/transactions/category-select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

interface CategoryCellProps {
    transaction: DecryptedTransaction;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onUpdate: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    className?: string;
    withoutChevronIcon?: boolean;
}

export function CategoryCell({
    transaction,
    categories,
    accounts,
    banks,
    onUpdate,
    onCategorized,
    className,
    withoutChevronIcon,
}: CategoryCellProps) {
    const [isUpdating, setIsUpdating] = useState(false);
    const isMobile = useIsMobile();

    async function handleCategoryChange(value: string) {
        const categoryId = value === 'null' ? null : value;

        setIsUpdating(true);
        try {
            const updateData: {
                category_id: string | null;
            } = {
                category_id: categoryId,
            };

            await transactionSyncService.update(transaction.id, updateData);

            const updatedCategory = categoryId
                ? categories.find((c) => c.id === categoryId) || null
                : null;

            const account = accounts.find(
                (a) => a.id === transaction.account_id,
            );
            const bank = account?.bank?.id
                ? banks.find((b) => b.id === account.bank!.id)
                : undefined;

            const updatedTransaction: DecryptedTransaction = {
                ...transaction,
                category_id: categoryId,
                category: updatedCategory,
                category_source: categoryId ? 'manual' : null,
                ai_confidence: null,
                ai_categorized: false,
                account,
                bank,
            };

            onUpdate(updatedTransaction);

            if (updatedCategory) {
                onCategorized?.(
                    updatedTransaction,
                    updatedCategory,
                    'transaction_table',
                );
            }
        } catch (error) {
            console.error('Failed to update category:', error);
        } finally {
            setIsUpdating(false);
        }
    }

    const isAiCategorized = transaction.ai_categorized === true;
    const confidencePercent =
        transaction.ai_confidence != null
            ? Math.round(transaction.ai_confidence * 100)
            : null;

    const aiNote =
        confidencePercent != null
            ? __('Categorized by AI with :confidence% confident', {
                  confidence: confidencePercent,
              })
            : __('Categorized by AI');

    // On mobile there is no hover, so the confidence is shown as a row at the
    // top of the open dropdown instead of in a tooltip.
    const aiHeader =
        isAiCategorized && isMobile ? (
            <div className="flex items-center gap-2 border-b px-3 py-2 text-xs text-muted-foreground">
                <AiSparkleIcon className="h-3.5 w-3.5 shrink-0" />
                <span>{aiNote}</span>
            </div>
        ) : undefined;

    const aiIcon = (
        <span className="inline-flex" aria-label={__('Categorized by AI')}>
            <AiSparkleIcon className="h-3.5 w-3.5" />
        </span>
    );

    return (
        <div className="flex w-full items-center gap-1">
            <div className="min-w-0 flex-1">
                <CategorySelect
                    value={
                        transaction.category_id
                            ? String(transaction.category_id)
                            : 'null'
                    }
                    onValueChange={handleCategoryChange}
                    categories={categories}
                    disabled={isUpdating}
                    placeholder={__('Uncategorized')}
                    triggerClassName={cn(
                        'h-auto w-full border-0 bg-transparent p-0 shadow-none focus:ring-0',
                        className || '',
                    )}
                    showUncategorized={true}
                    withoutChevronIcon={withoutChevronIcon}
                    header={aiHeader}
                />
            </div>

            {/* Trailing icon in a fixed-width slot pinned to the column's right
                edge — always reserved (empty when not AI) so the icon lines up
                horizontally on every row. Desktop shows confidence on hover;
                mobile relies on the dropdown header. */}
            <span className="flex w-3.5 shrink-0 items-center justify-center">
                {isAiCategorized &&
                    (isMobile ? (
                        aiIcon
                    ) : (
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    {aiIcon}
                                </TooltipTrigger>
                                <TooltipContent>{aiNote}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    ))}
            </span>
        </div>
    );
}
