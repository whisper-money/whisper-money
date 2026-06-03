import {
    index as indexBalances,
    store as storeBalance,
} from '@/actions/App/Http/Controllers/AccountBalanceController';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useSyncContext } from '@/contexts/sync-context';
import { useLocale } from '@/hooks/use-locale';
import { decrypt, importKey } from '@/lib/crypto';
import { getCsrfToken } from '@/lib/csrf';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { appendNoteIfNotPresent } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { getYear, parseISO } from 'date-fns';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface EditTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'edit_transaction_modal',
    ) => void;
    onLabelCreated?: (label: Label) => void;
    mode: 'create' | 'edit';
    initialAccountId?: string | null;
}

export function EditTransactionDialog({
    transaction,
    categories,
    accounts,
    banks,
    labels,
    automationRules = [],
    open,
    onOpenChange,
    onSuccess,
    onCategorized,
    onLabelCreated,
    mode,
    initialAccountId = null,
}: EditTransactionDialogProps) {
    const locale = useLocale();
    const STORAGE_KEY_UPDATE_BALANCE =
        'whisper_money_update_balance_on_transaction';

    const { sync } = useSyncContext();
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [amount, setAmount] = useState<number>(0);
    const [accountId, setAccountId] = useState<string>('');
    const [categoryId, setCategoryId] = useState<string>('null');
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [decryptedAccountNames, setDecryptedAccountNames] = useState<
        Map<string, string>
    >(new Map());
    const [updateAccountBalance, setUpdateAccountBalance] = useState(() => {
        if (typeof window !== 'undefined') {
            const stored = localStorage.getItem(STORAGE_KEY_UPDATE_BALANCE);
            return stored === 'true';
        }
        return false;
    });

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setAmount(transaction.amount);
            setAccountId(transaction.account_id);
            setCategoryId(transaction.category_id || 'null');
            setSelectedLabelIds(
                transaction.label_ids ||
                    transaction.labels?.map((l) => l.id) ||
                    [],
            );
            setNotes(transaction.decryptedNotes || '');
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setAmount(0);
            const availableAccounts = filterTransactionalAccounts(accounts);
            const initialAccount = availableAccounts.find(
                (account) => account.id === initialAccountId,
            );
            setAccountId(
                initialAccount?.id ??
                    (availableAccounts.length > 0
                        ? availableAccounts[0].id
                        : ''),
            );
            setCategoryId('null');
            setSelectedLabelIds([]);
            setNotes('');
        }
    }, [mode, transaction, open, accounts, initialAccountId]);

    useEffect(() => {
        if (!open || mode !== 'create') return;

        async function decryptAccountNames() {
            const keyString = getStoredKey();

            try {
                let key: CryptoKey | null = null;
                if (keyString) {
                    key = await importKey(keyString);
                }

                const decryptedNames = new Map<string, string>();

                await Promise.all(
                    accounts.map(async (account) => {
                        if (!account.encrypted) {
                            decryptedNames.set(account.id, account.name);
                            return;
                        }

                        if (!key || !account.name_iv) {
                            decryptedNames.set(account.id, '[Encrypted]');
                            return;
                        }

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

    async function checkAndApplyAutomationRules() {
        if (mode !== 'create' || automationRules.length === 0) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        const keyString = getStoredKey();
        if (!keyString) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        const key = await importKey(keyString);

        const result = await evaluateRulesForNewTransaction(
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
            key,
        );

        if (!result) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        let finalNotes = notes.trim();
        const finalNotesIv = null;

        if (result.note && result.noteIv) {
            const decryptedRuleNote = await decrypt(
                result.note,
                key,
                result.noteIv,
            );

            finalNotes = appendNoteIfNotPresent(
                finalNotes || undefined,
                decryptedRuleNote,
            );
        }

        return {
            categoryId: result.categoryId,
            labelIds: result.labelIds || [],
            matchedLabels: result.labels || [],
            notes: finalNotes || null,
            notesIv: finalNotesIv,
            ruleName: result.rule.title,
        };
    }

    function handleUpdateBalanceChange(checked: boolean) {
        setUpdateAccountBalance(checked);
        localStorage.setItem(STORAGE_KEY_UPDATE_BALANCE, String(checked));
    }

    async function updateBalanceForTransaction(
        accountIdToUpdate: string,
        transactionDateStr: string,
        transactionAmount: number,
    ) {
        const xsrfToken = getCsrfToken();

        try {
            // Fetch balances from backend
            const balancesResponse = await fetch(
                indexBalances.url(accountIdToUpdate),
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            if (!balancesResponse.ok) {
                throw new Error('Failed to fetch balances');
            }

            const balancesData = await balancesResponse.json();
            const accountBalances = (balancesData.data || []).sort(
                (a: { balance_date: string }, b: { balance_date: string }) =>
                    new Date(b.balance_date).getTime() -
                    new Date(a.balance_date).getTime(),
            );

            const latestBalance =
                accountBalances.length > 0 ? accountBalances[0].balance : 0;

            const newBalance = latestBalance + transactionAmount;

            // Store new balance via backend
            const storeResponse = await fetch(
                storeBalance.url(accountIdToUpdate),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        balance_date: transactionDateStr,
                        balance: newBalance,
                    }),
                },
            );

            if (!storeResponse.ok) {
                throw new Error('Failed to store balance');
            }
        } catch (error) {
            console.error('Failed to update account balance:', error);
            toast.error(
                __('Transaction created, but failed to update balance'),
            );
        }
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (mode === 'create') {
            if (!description.trim()) {
                toast.error(__('Description is required'));
                return;
            }
            if (amount === 0) {
                toast.error(__('Amount is required'));
                return;
            }
            if (!accountId) {
                toast.error(__('Account is required'));
                return;
            }
            if (!transactionDate) {
                toast.error(__('Date is required'));
                return;
            }
        } else if (
            mode === 'edit' &&
            transaction?.source === 'manually_created'
        ) {
            if (!description.trim()) {
                toast.error(__('Description is required'));
                return;
            }
        }

        setIsSubmitting(true);
        try {
            const trimmedDescription = description.trim();

            if (mode === 'create') {
                const ruleResult = await checkAndApplyAutomationRules();

                let finalCategoryId = categoryId === 'null' ? null : categoryId;
                let finalNotes = notes.trim();
                let finalLabelIds = [...selectedLabelIds];

                if (ruleResult.categoryId && !finalCategoryId) {
                    finalCategoryId = ruleResult.categoryId;
                }
                if (ruleResult.notes) {
                    finalNotes = ruleResult.notes;
                }
                if (
                    ruleResult.labelIds.length > 0 &&
                    finalLabelIds.length === 0
                ) {
                    finalLabelIds = [...ruleResult.labelIds];
                }

                const finalDescription = trimmedDescription;
                const finalDescriptionIv = null;
                const encryptedNotes = finalNotes || null;
                const notesIv = null;

                const selectedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                if (!selectedAccount) {
                    throw new Error(__('Selected account not found'));
                }

                const createdTransaction = await transactionSyncService.create({
                    user_id: '00000000-0000-0000-0000-000000000000',
                    account_id: accountId,
                    category_id: finalCategoryId,
                    description: finalDescription,
                    description_iv: finalDescriptionIv,
                    transaction_date: transactionDate,
                    amount: amount,
                    currency_code: selectedAccount.currency_code,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    creditor_name: null,
                    debtor_name: null,
                    source: 'manually_created' as const,
                    label_ids:
                        finalLabelIds.length > 0 ? finalLabelIds : undefined,
                });

                const updatedCategory = finalCategoryId
                    ? categories.find(
                          (category) => category.id === finalCategoryId,
                      ) || null
                    : null;

                const transactionLabels = labels.filter((l) =>
                    finalLabelIds.includes(l.id),
                );

                const newTransaction: DecryptedTransaction = {
                    ...createdTransaction,
                    decryptedDescription: trimmedDescription,
                    decryptedNotes: finalNotes || null,
                    category: updatedCategory,
                    account: selectedAccount,
                    bank: selectedAccount.bank?.id
                        ? banks.find((b) => b.id === selectedAccount.bank?.id)
                        : undefined,
                    labels: transactionLabels,
                    label_ids: finalLabelIds,
                };

                if (updateAccountBalance) {
                    await updateBalanceForTransaction(
                        accountId,
                        transactionDate,
                        amount,
                    );
                }

                toast.success(__('Transaction created successfully'));
                if (ruleResult.ruleName) {
                    toast.success(
                        __('Rule ":rule" applied', {
                            rule: ruleResult.ruleName,
                        }),
                    );
                }

                onSuccess(newTransaction);
                onOpenChange(false);

                // Sync to update IndexedDB
                sync();
            } else {
                if (!transaction) {
                    return;
                }

                const selectedCategoryId =
                    categoryId === 'null' ? null : categoryId;
                const trimmedNotes = notes.trim();
                const trimmedDescription = description.trim();

                let encryptedNotes: string | null = null;
                let notesIv: string | null = null;

                encryptedNotes = trimmedNotes || null;
                notesIv = null;

                const updateData: {
                    category_id: string | null;
                    notes: string | null;
                    notes_iv: string | null;
                    description?: string;
                    description_iv?: string | null;
                    label_ids?: string[];
                } = {
                    category_id: selectedCategoryId,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                };

                let finalDecryptedDescription =
                    transaction.decryptedDescription;

                if (
                    transaction.source === 'manually_created' &&
                    trimmedDescription
                ) {
                    updateData.description = trimmedDescription;
                    updateData.description_iv = null;
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

                const selectedLabels = labels.filter((label) =>
                    selectedLabelIds.includes(label.id),
                );

                const updatedTransaction: DecryptedTransaction = {
                    ...transaction,
                    category_id: selectedCategoryId,
                    category: updatedCategory,
                    decryptedDescription: finalDecryptedDescription,
                    description:
                        updateData.description ?? transaction.description,
                    description_iv:
                        updateData.description_iv ?? transaction.description_iv,
                    decryptedNotes: trimmedNotes || null,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                    labels: selectedLabels,
                    updated_at:
                        updatedRecord?.updated_at ?? transaction.updated_at,
                };

                toast.success(__('Transaction updated successfully'));
                onSuccess(updatedTransaction);

                if (
                    selectedCategoryId &&
                    selectedCategoryId !== transaction.category_id &&
                    updatedCategory
                ) {
                    onCategorized?.(
                        updatedTransaction,
                        updatedCategory,
                        'edit_transaction_modal',
                    );
                }
                onOpenChange(false);

                // Sync to update IndexedDB
                sync();
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            toast.error(
                mode === 'create'
                    ? __('Failed to create transaction')
                    : __('Failed to update transaction'),
            );
        } finally {
            setIsSubmitting(false);
        }
    }

    const selectedAccount = accounts.find((acc) => acc.id === accountId);
    const transactionalAccounts = filterTransactionalAccounts(accounts);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle>
                        {mode === 'create'
                            ? __('Add Transaction')
                            : __('Edit Transaction')}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'create'
                            ? __('Create a new transaction.')
                            : __(
                                  'Update the category and notes for this transaction.',
                              )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="date"
                                className={
                                    mode === 'edit'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                {__('Date')}
                            </FormLabel>
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
                                            const formatted = formatDate(
                                                date,
                                                formatString,
                                                locale,
                                            );
                                            // Capitalize first letter
                                            return (
                                                formatted
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                formatted.slice(1)
                                            );
                                        })()}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="description"
                                className={
                                    mode === 'edit' &&
                                    transaction?.source === 'imported'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                {__('Description')}
                            </FormLabel>
                            {mode === 'create' ||
                            (mode === 'edit' &&
                                transaction?.source === 'manually_created') ? (
                                <Textarea
                                    id="description"
                                    value={description}
                                    onChange={(e) =>
                                        setDescription(e.target.value)
                                    }
                                    placeholder={__('Transaction description')}
                                    disabled={isSubmitting}
                                    required
                                    rows={3}
                                />
                            ) : (
                                <div className="space-y-1.5">
                                    <Textarea
                                        id="description"
                                        value={
                                            transaction?.decryptedDescription ??
                                            ''
                                        }
                                        disabled
                                        className="bg-muted"
                                        rows={3}
                                    />

                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            'This transaction was imported from a\n                                        file. The description cannot be\n                                        modified.',
                                        )}
                                    </p>
                                </div>
                            )}
                        </div>

                        {mode === 'edit' &&
                            (transaction?.creditor_name ||
                                transaction?.debtor_name) && (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {transaction.creditor_name && (
                                        <div className="space-y-2">
                                            <FormLabel className="text-sm text-muted-foreground">
                                                {__('Creditor')}
                                            </FormLabel>
                                            <Input
                                                value={
                                                    transaction.creditor_name
                                                }
                                                disabled
                                                readOnly
                                                className="bg-muted"
                                            />
                                        </div>
                                    )}

                                    {transaction.debtor_name && (
                                        <div className="space-y-2">
                                            <FormLabel className="text-sm text-muted-foreground">
                                                {__('Debtor')}
                                            </FormLabel>
                                            <Input
                                                value={transaction.debtor_name}
                                                disabled
                                                readOnly
                                                className="bg-muted"
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="amount"
                                className={
                                    mode === 'edit'
                                        ? 'text-sm text-muted-foreground'
                                        : ''
                                }
                            >
                                {__('Amount')}
                            </FormLabel>
                            {mode === 'create' ? (
                                <>
                                    <AmountInput
                                        id="amount"
                                        value={amount}
                                        onChange={setAmount}
                                        currencyCode={
                                            selectedAccount?.currency_code ||
                                            'USD'
                                        }
                                        disabled={isSubmitting}
                                        required
                                    />

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="update-balance"
                                            checked={updateAccountBalance}
                                            onCheckedChange={(checked) =>
                                                handleUpdateBalanceChange(
                                                    checked === true,
                                                )
                                            }
                                            disabled={isSubmitting}
                                        />

                                        <FormLabel
                                            htmlFor="update-balance"
                                            className="cursor-pointer font-normal"
                                        >
                                            {__('Update account balance')}
                                        </FormLabel>
                                    </div>
                                </>
                            ) : (
                                <div className="text-sm font-medium">
                                    {transaction &&
                                        new Intl.NumberFormat(locale, {
                                            style: 'currency',
                                            currency: transaction.currency_code,
                                        })
                                            .format(transaction.amount / 100)
                                            .replace(/\s/g, '\u202F')}
                                </div>
                            )}
                        </div>

                        {mode === 'create' && (
                            <div className="space-y-2">
                                <FormLabel htmlFor="account">
                                    {__('Account')}
                                </FormLabel>
                                <Select
                                    value={accountId}
                                    onValueChange={setAccountId}
                                    disabled={isSubmitting}
                                >
                                    <SelectTrigger
                                        id="account"
                                        data-testid="account-select"
                                    >
                                        <SelectValue
                                            placeholder={__('Select account')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {transactionalAccounts.map(
                                            (account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {decryptedAccountNames.get(
                                                        account.id,
                                                    ) || __('[Loading...]')}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div className="space-y-2">
                            <FormLabel htmlFor="category">
                                {__('Category')}
                            </FormLabel>
                            <CategorySelect
                                value={categoryId}
                                onValueChange={setCategoryId}
                                categories={categories}
                                disabled={isSubmitting}
                                placeholder={__('Uncategorized')}
                                triggerClassName="w-full"
                                showUncategorized={true}
                                data-testid="category-select"
                            />
                        </div>

                        <div className="space-y-2">
                            <FormLabel>{__('Labels')}</FormLabel>
                            <LabelCombobox
                                value={selectedLabelIds}
                                onValueChange={setSelectedLabelIds}
                                labels={labels}
                                disabled={isSubmitting}
                                placeholder={__('Add labels...')}
                                allowCreate={true}
                                onLabelCreated={onLabelCreated}
                            />
                        </div>

                        <div className="space-y-2">
                            <FormLabel htmlFor="notes">{__('Notes')}</FormLabel>
                            <Textarea
                                id="notes"
                                placeholder={__('Add notes...')}
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
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting}
                            data-testid="submit-transaction"
                        >
                            {isSubmitting
                                ? __('Saving...')
                                : mode === 'create'
                                  ? __('Create Transaction')
                                  : __('Save Changes')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
