import { CategorySelect } from '@/components/transactions/category-select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

    // AI-categorized rows get a soft Gemini-style gradient glow on the dropdown
    // instead of a separate icon. The box model (padding/negative margin) is
    // identical on every row so categories stay aligned whether glowing or not.
    const aiGlow =
        'bg-gradient-to-r from-[#4796E3]/10 via-[#9177C7]/10 to-[#D56F82]/10 ring-1 ring-[#9177C7]/30 shadow-[0_0_12px_-2px_rgba(145,119,199,0.55)] dark:from-[#4796E3]/20 dark:via-[#9177C7]/20 dark:to-[#D56F82]/20 dark:ring-[#9177C7]/40';

    const select = (
        <span className="flex w-full min-w-0">
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
                    'h-auto w-auto rounded-md border-0 bg-transparent px-1 py-0.5 -mx-1 -my-0.5 shadow-none focus:ring-0',
                    className || '',
                    isAiCategorized && aiGlow,
                )}
                showUncategorized={true}
                withoutChevronIcon={withoutChevronIcon}
            />
        </span>
    );

    if (!isAiCategorized) {
        return select;
    }

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>{select}</TooltipTrigger>
                <TooltipContent>
                    {confidencePercent != null
                        ? __('Categorized by AI · :confidence% confident', {
                              confidence: confidencePercent,
                          })
                        : __('Categorized by AI')}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
