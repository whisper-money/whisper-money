import { CategorySelect } from '@/components/transactions/category-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { format, parseISO } from 'date-fns';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface EditTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: (transaction: DecryptedTransaction) => void;
}

export function EditTransactionDialog({
    transaction,
    categories,
    open,
    onOpenChange,
    onSuccess,
}: EditTransactionDialogProps) {
    const { isKeySet } = useEncryptionKey();
    const [categoryId, setCategoryId] = useState<string>('null');
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (transaction) {
            setCategoryId(
                transaction.category_id
                    ? String(transaction.category_id)
                    : 'null',
            );
            setNotes(transaction.decryptedNotes || '');
        }
    }, [transaction]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!transaction) {
            return;
        }

        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to update transactions',
            );
            return;
        }

        setIsSubmitting(true);
        try {
            const selectedCategoryId =
                categoryId === 'null' ? null : parseInt(categoryId, 10);
            const trimmedNotes = notes.trim();
            let encryptedNotes: string | null = null;
            let notesIv: string | null = null;

            if (trimmedNotes) {
                const keyString = getStoredKey();
                if (!keyString) {
                    throw new Error('Encryption key not available');
                }
                const key = await importKey(keyString);
                const encrypted = await encrypt(trimmedNotes, key);
                encryptedNotes = encrypted.encrypted;
                notesIv = encrypted.iv;
            }

            await transactionSyncService.update(transaction.id, {
                category_id: selectedCategoryId,
                notes: encryptedNotes,
                notes_iv: notesIv,
            });

            const updatedRecord = await transactionSyncService.getById(
                transaction.id,
            );
            const updatedCategory = selectedCategoryId
                ? categories.find(
                      (category) => category.id === selectedCategoryId,
                  ) || null
                : null;

            const updatedTransaction: DecryptedTransaction = {
                ...transaction,
                category_id: selectedCategoryId,
                category: updatedCategory,
                decryptedNotes: trimmedNotes || null,
                notes: encryptedNotes,
                notes_iv: notesIv,
                updated_at: updatedRecord?.updated_at ?? transaction.updated_at,
            };

            onSuccess(updatedTransaction);
            onOpenChange(false);
        } catch (error) {
            console.error('Failed to update transaction:', error);
        } finally {
            setIsSubmitting(false);
        }
    }

    if (!transaction) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle>Edit Transaction</DialogTitle>
                    <DialogDescription>
                        Update the category and notes for this transaction.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label className="text-sm text-muted-foreground">
                                Date
                            </Label>
                            <div className="text-sm">
                                {format(
                                    parseISO(transaction.transaction_date),
                                    'PPP',
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-sm text-muted-foreground">
                                Description
                            </Label>
                            <div className="text-sm">
                                {transaction.decryptedDescription}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-sm text-muted-foreground">
                                Amount
                            </Label>
                            <div className="text-sm font-medium">
                                {new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: transaction.currency_code,
                                }).format(parseFloat(transaction.amount))}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="category">Category</Label>
                            <CategorySelect
                                value={categoryId}
                                onValueChange={setCategoryId}
                                categories={categories}
                                disabled={isSubmitting}
                                placeholder="Uncategorized"
                                triggerClassName="w-full"
                                showUncategorized={true}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                placeholder="Add notes..."
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={3}
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
