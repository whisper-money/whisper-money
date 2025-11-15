import { CategorySelect } from '@/components/transactions/category-select';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { format, getYear, parseISO } from 'date-fns';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface EditTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: (transaction: DecryptedTransaction) => void;
    mode: 'create' | 'edit';
}

export function EditTransactionDialog({
    transaction,
    categories,
    accounts,
    banks,
    open,
    onOpenChange,
    onSuccess,
    mode,
}: EditTransactionDialogProps) {
    const { isKeySet } = useEncryptionKey();
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [amount, setAmount] = useState<number>(0);
    const [accountId, setAccountId] = useState<string>('');
    const [categoryId, setCategoryId] = useState<string>('null');
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [decryptedAccountNames, setDecryptedAccountNames] = useState<
        Map<number, string>
    >(new Map());

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setAmount(transaction.amount);
            setAccountId(String(transaction.account_id));
            setCategoryId(
                transaction.category_id
                    ? String(transaction.category_id)
                    : 'null',
            );
            setNotes(transaction.decryptedNotes || '');
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setAmount(0);
            setAccountId(accounts.length > 0 ? String(accounts[0].id) : '');
            setCategoryId('null');
            setNotes('');
        }
    }, [mode, transaction, open, accounts]);

    useEffect(() => {
        if (!open || mode !== 'create') return;

        async function decryptAccountNames() {
            const keyString = getStoredKey();
            if (!keyString) {
                return;
            }

            try {
                const key = await importKey(keyString);
                const decryptedNames = new Map<number, string>();

                await Promise.all(
                    accounts.map(async (account) => {
                        try {
                            const decryptedName = await decrypt(
                                account.name,
                                key,
                                account.name_iv,
                            );
                            decryptedNames.set(account.id, decryptedName);
                        } catch (error) {
                            console.error(
                                'Failed to decrypt account name:',
                                account.id,
                                error,
                            );
                            decryptedNames.set(account.id, '[Encrypted]');
                        }
                    }),
                );

                setDecryptedAccountNames(decryptedNames);
            } catch (error) {
                console.error('Failed to decrypt account names:', error);
            }
        }

        decryptAccountNames();
    }, [open, mode, accounts]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to save transactions',
            );
            return;
        }

        if (mode === 'create') {
            if (!description.trim()) {
                toast.error('Description is required');
                return;
            }
            if (amount === 0) {
                toast.error('Amount is required');
                return;
            }
            if (!accountId) {
                toast.error('Account is required');
                return;
            }
            if (!transactionDate) {
                toast.error('Date is required');
                return;
            }
        }

        setIsSubmitting(true);
        try {
            const selectedCategoryId =
                categoryId === 'null' ? null : parseInt(categoryId, 10);
            const trimmedNotes = notes.trim();
            const trimmedDescription = description.trim();

            const keyString = getStoredKey();
            if (!keyString) {
                throw new Error('Encryption key not available');
            }
            const key = await importKey(keyString);

            let encryptedNotes: string | null = null;
            let notesIv: string | null = null;

            if (trimmedNotes) {
                const encrypted = await encrypt(trimmedNotes, key);
                encryptedNotes = encrypted.encrypted;
                notesIv = encrypted.iv;
            }

            if (mode === 'create') {
                const encryptedDescription = await encrypt(
                    trimmedDescription,
                    key,
                );

                const selectedAccount = accounts.find(
                    (acc) => acc.id === parseInt(accountId, 10),
                );
                if (!selectedAccount) {
                    throw new Error('Selected account not found');
                }

                const createdTransaction = await transactionSyncService.create({
                    user_id: 0,
                    account_id: parseInt(accountId, 10),
                    category_id: selectedCategoryId,
                    description: encryptedDescription.encrypted,
                    description_iv: encryptedDescription.iv,
                    transaction_date: transactionDate,
                    amount: amount,
                    currency_code: selectedAccount.currency_code,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                });

                const updatedCategory = selectedCategoryId
                    ? categories.find(
                          (category) => category.id === selectedCategoryId,
                      ) || null
                    : null;

                const newTransaction: DecryptedTransaction = {
                    ...createdTransaction,
                    decryptedDescription: trimmedDescription,
                    decryptedNotes: trimmedNotes || null,
                    category: updatedCategory,
                    account: selectedAccount,
                    bank: selectedAccount.bank?.id
                        ? banks.find((b) => b.id === selectedAccount.bank?.id)
                        : undefined,
                };

                toast.success('Transaction created successfully');
                onSuccess(newTransaction);
                onOpenChange(false);
            } else {
                if (!transaction) {
                    return;
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
                    updated_at:
                        updatedRecord?.updated_at ?? transaction.updated_at,
                };

                toast.success('Transaction updated successfully');
                onSuccess(updatedTransaction);
                onOpenChange(false);
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            toast.error(
                `Failed to ${mode === 'create' ? 'create' : 'update'} transaction`,
            );
        } finally {
            setIsSubmitting(false);
        }
    }

    const selectedAccount = accounts.find(
        (acc) => acc.id === parseInt(accountId, 10),
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle>
                        {mode === 'create'
                            ? 'Add Transaction'
                            : 'Edit Transaction'}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'create'
                            ? 'Create a new transaction.'
                            : 'Update the category and notes for this transaction.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label
                                htmlFor="date"
                                className={
                                    mode === 'edit'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                Date
                            </Label>
                            {mode === 'create' ? (
                                <Input
                                    id="date"
                                    type="date"
                                    value={transactionDate}
                                    onChange={(e) =>
                                        setTransactionDate(e.target.value)
                                    }
                                    disabled={isSubmitting}
                                    required
                                />
                            ) : (
                                <div className="text-sm">
                                    {transaction &&
                                        (() => {
                                            const date = parseISO(
                                                transaction.transaction_date,
                                            );
                                            const currentYear = getYear(
                                                new Date(),
                                            );
                                            const transactionYear =
                                                getYear(date);
                                            const formatString =
                                                transactionYear === currentYear
                                                    ? 'MMMM d'
                                                    : 'MMMM d, yyyy';
                                            return format(date, formatString);
                                        })()}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="description"
                                className={
                                    mode === 'edit'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                Description
                            </Label>
                            {mode === 'create' ? (
                                <Input
                                    id="description"
                                    type="text"
                                    value={description}
                                    onChange={(e) =>
                                        setDescription(e.target.value)
                                    }
                                    placeholder="Transaction description"
                                    disabled={isSubmitting}
                                    required
                                />
                            ) : (
                                <div className="text-sm">
                                    {transaction?.decryptedDescription}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="amount"
                                className={
                                    mode === 'edit'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                Amount
                            </Label>
                            {mode === 'create' ? (
                                <AmountInput
                                    id="amount"
                                    value={amount}
                                    onChange={setAmount}
                                    currencyCode={
                                        selectedAccount?.currency_code || 'USD'
                                    }
                                    disabled={isSubmitting}
                                    required
                                />
                            ) : (
                                <div className="text-sm font-medium">
                                    {transaction &&
                                        new Intl.NumberFormat('en-US', {
                                            style: 'currency',
                                            currency: transaction.currency_code,
                                        }).format(transaction.amount / 100)}
                                </div>
                            )}
                        </div>

                        {mode === 'create' && (
                            <div className="space-y-2">
                                <Label htmlFor="account">Account</Label>
                                <Select
                                    value={accountId}
                                    onValueChange={setAccountId}
                                    disabled={isSubmitting}
                                >
                                    <SelectTrigger id="account">
                                        <SelectValue placeholder="Select account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {decryptedAccountNames.get(
                                                    account.id,
                                                ) || '[Loading...]'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

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
                                disabled={isSubmitting}
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
                            {isSubmitting
                                ? 'Saving...'
                                : mode === 'create'
                                  ? 'Create Transaction'
                                  : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
