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
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
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
        Map<string, string>
    >(new Map());
    const [automationRules, setAutomationRules] = useState<AutomationRule[]>([]);

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setAmount(transaction.amount);
            setAccountId(transaction.account_id);
            setCategoryId(transaction.category_id || 'null');
            setNotes(transaction.decryptedNotes || '');
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setAmount(0);
            setAccountId(accounts.length > 0 ? accounts[0].id : '');
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
                const decryptedNames = new Map<string, string>();

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

    useEffect(() => {
        if (!open || mode !== 'create') return;

        async function loadAutomationRules() {
            try {
                const rules = await automationRuleSyncService.getAll();
                setAutomationRules(rules);
            } catch (error) {
                console.error('Failed to load automation rules:', error);
            }
        }

        loadAutomationRules();
    }, [open, mode]);

    async function checkAndApplyAutomationRules() {
        if (mode !== 'create' || automationRules.length === 0) {
            return { categoryId: null, notes: null, notesIv: null, ruleName: null };
        }

        const result = evaluateRulesForNewTransaction(
            {
                description: description.trim(),
                amount: amount / 100,
                transaction_date: transactionDate,
                account_id: accountId,
                notes: notes.trim() || undefined,
            },
            automationRules,
            categories,
            accounts,
            banks,
        );

        if (!result) {
            return { categoryId: null, notes: null, notesIv: null, ruleName: null };
        }

        let finalNotes = notes.trim();
        const finalNotesIv = null;

        if (result.note && result.noteIv) {
            const keyString = getStoredKey();
            if (keyString) {
                const key = await importKey(keyString);
                const decryptedRuleNote = await decrypt(
                    result.note,
                    key,
                    result.noteIv,
                );

                if (finalNotes) {
                    finalNotes = `${finalNotes}\n${decryptedRuleNote}`;
                } else {
                    finalNotes = decryptedRuleNote;
                }
            }
        }

        return {
            categoryId: result.categoryId,
            notes: finalNotes || null,
            notesIv: finalNotesIv,
            ruleName: result.rule.title,
        };
    }

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
        } else if (mode === 'edit' && transaction?.source === 'manually_created') {
            if (!description.trim()) {
                toast.error('Description is required');
                return;
            }
        }

        setIsSubmitting(true);
        try {
            const trimmedDescription = description.trim();
            const keyString = getStoredKey();
            if (!keyString) {
                throw new Error('Encryption key not available');
            }
            const key = await importKey(keyString);

            if (mode === 'create') {
                const ruleResult = await checkAndApplyAutomationRules();

                let finalCategoryId = categoryId === 'null' ? null : categoryId;
                let finalNotes = notes.trim();

                if (ruleResult.categoryId) {
                    finalCategoryId = ruleResult.categoryId;
                }
                if (ruleResult.notes) {
                    finalNotes = ruleResult.notes;
                }

                let encryptedNotes: string | null = null;
                let notesIv: string | null = null;

                if (finalNotes) {
                    const encrypted = await encrypt(finalNotes, key);
                    encryptedNotes = encrypted.encrypted;
                    notesIv = encrypted.iv;
                }

                const encryptedDescription = await encrypt(
                    trimmedDescription,
                    key,
                );

                const selectedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                if (!selectedAccount) {
                    throw new Error('Selected account not found');
                }

                const createdTransaction = await transactionSyncService.create({
                    user_id: '00000000-0000-0000-0000-000000000000',
                    account_id: accountId,
                    category_id: finalCategoryId,
                    description: encryptedDescription.encrypted,
                    description_iv: encryptedDescription.iv,
                    transaction_date: transactionDate,
                    amount: amount,
                    currency_code: selectedAccount.currency_code,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    source: 'manually_created' as const,
                });

                const updatedCategory = finalCategoryId
                    ? categories.find(
                        (category) => category.id === finalCategoryId,
                    ) || null
                    : null;

                const newTransaction: DecryptedTransaction = {
                    ...createdTransaction,
                    decryptedDescription: trimmedDescription,
                    decryptedNotes: finalNotes || null,
                    category: updatedCategory,
                    account: selectedAccount,
                    bank: selectedAccount.bank?.id
                        ? banks.find((b) => b.id === selectedAccount.bank?.id)
                        : undefined,
                };

                toast.success('Transaction created successfully');
                if (ruleResult.ruleName) {
                    toast.success(`Rule "${ruleResult.ruleName}" applied`);
                }

                onSuccess(newTransaction);
                onOpenChange(false);
            } else {
                if (!transaction) {
                    return;
                }

                const selectedCategoryId = categoryId === 'null' ? null : categoryId;
                const trimmedNotes = notes.trim();
                const trimmedDescription = description.trim();

                let encryptedNotes: string | null = null;
                let notesIv: string | null = null;

                if (trimmedNotes) {
                    const encrypted = await encrypt(trimmedNotes, key);
                    encryptedNotes = encrypted.encrypted;
                    notesIv = encrypted.iv;
                }

                const updateData: {
                    category_id: string | null;
                    notes: string | null;
                    notes_iv: string | null;
                    description?: string;
                    description_iv?: string;
                } = {
                    category_id: selectedCategoryId,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                };

                let finalDecryptedDescription = transaction.decryptedDescription;

                if (transaction.source === 'manually_created' && trimmedDescription) {
                    const encryptedDescription = await encrypt(trimmedDescription, key);
                    updateData.description = encryptedDescription.encrypted;
                    updateData.description_iv = encryptedDescription.iv;
                    finalDecryptedDescription = trimmedDescription;
                }

                await transactionSyncService.update(transaction.id, updateData);

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
                    decryptedDescription: finalDecryptedDescription,
                    description: updateData.description ?? transaction.description,
                    description_iv: updateData.description_iv ?? transaction.description_iv,
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
        (acc) => acc.id === accountId,
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
                                    mode === 'edit' && transaction?.source === 'imported'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                Description
                            </Label>
                            {mode === 'create' || (mode === 'edit' && transaction?.source === 'manually_created') ? (
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
                                <div className="space-y-1.5">
                                    <Input
                                        id="description"
                                        type="text"
                                        value={transaction?.decryptedDescription ?? ''}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        This transaction was imported from a file. The description cannot be modified.
                                    </p>
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
