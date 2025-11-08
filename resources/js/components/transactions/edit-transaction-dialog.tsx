import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { transactionSyncService } from '@/services/transaction-sync';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { format, parseISO } from 'date-fns';

interface EditTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: () => void;
}

export function EditTransactionDialog({
    transaction,
    categories,
    open,
    onOpenChange,
    onSuccess,
}: EditTransactionDialogProps) {
    const [categoryId, setCategoryId] = useState<string>('null');
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (transaction) {
            setCategoryId(
                transaction.category_id ? String(transaction.category_id) : 'null',
            );
            setNotes(transaction.decryptedNotes || '');
        }
    }, [transaction]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!transaction) {
            return;
        }

        setIsSubmitting(true);
        try {
            const updateData: {
                category_id: number | null;
                notes?: string;
                notes_iv?: string;
            } = {
                category_id: categoryId === 'null' ? null : parseInt(categoryId),
            };

            if (notes.trim()) {
                const keyString = getStoredKey();
                if (!keyString) {
                    throw new Error('Encryption key not available');
                }
                const key = await importKey(keyString);
                const encrypted = await encrypt(notes, key);
                updateData.notes = encrypted.encrypted;
                updateData.notes_iv = encrypted.iv;
            } else {
                updateData.notes = null;
                updateData.notes_iv = null;
            }

            await transactionSyncService.update(transaction.id, updateData);
            onSuccess();
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

    const selectedCategory = categories.find(
        (c) => c.id === parseInt(categoryId),
    );

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
                            <Label className="text-muted-foreground text-sm">
                                Date
                            </Label>
                            <div className="text-sm">
                                {format(parseISO(transaction.transaction_date), 'PPP')}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-muted-foreground text-sm">
                                Description
                            </Label>
                            <div className="text-sm">
                                {transaction.decryptedDescription}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-muted-foreground text-sm">
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
                            <Select
                                value={categoryId}
                                onValueChange={setCategoryId}
                            >
                                <SelectTrigger id="category">
                                    <SelectValue>
                                        {selectedCategory ? (
                                            <div className="flex items-center gap-2">
                                                <span>{selectedCategory.icon}</span>
                                                <span>{selectedCategory.name}</span>
                                            </div>
                                        ) : (
                                            <span className="text-zinc-500">
                                                Uncategorized
                                            </span>
                                        )}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="null">
                                        <span className="text-zinc-500">
                                            Uncategorized
                                        </span>
                                    </SelectItem>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            <div className="flex items-center gap-2">
                                                <span>{category.icon}</span>
                                                <span>{category.name}</span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
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

