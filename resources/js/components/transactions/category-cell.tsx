import { CategorySelect } from '@/components/transactions/category-select';
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
    className?: string;
    withoutChevronIcon?: boolean;
}

export function CategoryCell({
    transaction,
    categories,
    accounts,
    banks,
    onUpdate,
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
                ? banks.find((b) => b.id === account.bank.id)
                : undefined;

            const updatedTransaction: DecryptedTransaction = {
                ...transaction,
                category_id: categoryId,
                category: updatedCategory,
                account,
                bank,
            };

            onUpdate(updatedTransaction);
        } catch (error) {
            console.error('Failed to update category:', error);
        } finally {
            setIsUpdating(false);
        }
    }

    return (
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
                'h-auto w-auto border-0 bg-transparent p-0 shadow-none focus:ring-0',
                className || '',
            )}
            showUncategorized={true}
            withoutChevronIcon={withoutChevronIcon}
        />
    );
}
