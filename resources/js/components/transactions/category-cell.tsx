import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import * as Icons from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { type Account, type Bank } from '@/types/account';
import { transactionSyncService } from '@/services/transaction-sync';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';

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
    const [isUpdating, setIsUpdating] = useState(false);

    async function handleCategoryChange(value: string) {
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

            const account = accounts.find((a) => a.id === transaction.account_id);
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

    const currentCategory = transaction.category;
    const colorClasses = currentCategory
        ? getCategoryColorClasses(currentCategory.color)
        : null;
    const CurrentCategoryIconComponent = (currentCategory ? Icons[currentCategory.icon as keyof typeof Icons] : "CircleQuestionMark") as Icons.LucideIcon;

    return (
        <Select
            value={
                transaction.category_id
                    ? String(transaction.category_id)
                    : 'null'
            }
            onValueChange={handleCategoryChange}
            disabled={isUpdating}
        >
            <SelectTrigger className="h-auto w-auto border-0 bg-transparent p-0 shadow-none focus:ring-0">
                <SelectValue>
                    {currentCategory ? (
                        <Badge
                            className={`${colorClasses?.bg} ${colorClasses?.text} flex gap-2`}
                        >
                            <CurrentCategoryIconComponent className={`opacity-80 h-2 w-2 ${colorClasses?.text}`} />
                            {currentCategory.name}
                        </Badge>
                    ) : (
                        <Badge className="text-zinc-500 bg-zinc-50 dark:bg-zinc-950 flex gap-2">
                            <Icons.CircleQuestionMark className={`opacity-80 h-2 w-2 text-zinc-500`} />
                            Uncategorized
                        </Badge>
                    )}
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                {categories.map((category) => {
                    const IconComponent = Icons[category.icon as keyof typeof Icons] as Icons.LucideIcon;
                    const classes = getCategoryColorClasses(category.color);
                    return (
                        <SelectItem key={category.id} value={String(category.id)}>
                            <Badge className={`flex items-center gap-2 py-0.5 ${classes.bg} ${classes.text}`}>
                                <IconComponent className={`opacity-80 h-2 w-2 ${classes.text}`} />
                                <span>{category.name}</span>
                            </Badge>
                        </SelectItem>
                    );
                })}
            </SelectContent>
        </Select>
    );
}

