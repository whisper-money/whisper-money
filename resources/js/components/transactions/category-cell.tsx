import { CategorySelect } from '@/components/transactions/category-select';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { useState } from 'react';
import { toast } from 'sonner';

interface CategoryCellProps {
    transaction: DecryptedTransaction;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onUpdate: (transaction: DecryptedTransaction) => void;
}

export function CategoryCell({
    transaction,
    categories,
    accounts,
    banks,
    onUpdate,
}: CategoryCellProps) {
    const { isKeySet } = useEncryptionKey();
    const [isUpdating, setIsUpdating] = useState(false);

    async function handleCategoryChange(value: string) {
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to update transactions',
            );
            return;
        }

        const categoryId = value === 'null' ? null : parseInt(value);

        setIsUpdating(true);
        try {
            const updateData: {
                category_id: number | null;
                notes?: string;
                notes_iv?: string;
            } = {
                category_id: categoryId,
            };

            if (transaction.notes) {
                const keyString = getStoredKey();
                if (!keyString) {
                    throw new Error('Encryption key not available');
                }
                const key = await importKey(keyString);
                const encrypted = await encrypt(transaction.notes, key);
                updateData.notes = encrypted.encrypted;
                updateData.notes_iv = encrypted.iv;
            }

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
            placeholder="Uncategorized"
            triggerClassName="h-auto w-auto border-0 bg-transparent p-0 shadow-none focus:ring-0"
            showUncategorized={true}
        />
    );
}
