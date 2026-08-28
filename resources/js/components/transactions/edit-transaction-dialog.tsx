import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
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
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { canSplit } from '@/lib/transaction-splits';
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
import { router } from '@inertiajs/react';
import { getYear, parseISO } from 'date-fns';
import { Split, Trash2 } from 'lucide-react';
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
    onDelete?: (transaction: DecryptedTransaction) => void;
    onSplit?: (transaction: DecryptedTransaction) => void;
    mode: 'create' | 'edit';
    initialAccountId?: string | null;
}

/**
 * A transaction date as the dialog shows it in plain text: the year is dropped
 * when it is the current one, and the month name is capitalized for the locales
 * that lowercase it.
 */
function formatTransactionDate(date: string, locale: string): string {
    const parsed = parseISO(date);
    const formatString =
        getYear(parsed) === getYear(new Date()) ? 'MMMM d' : 'MMMM d, yyyy';
    const formatted = formatDate(parsed, formatString, locale);

    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
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
    onDelete,
    onSplit,
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
            const stored = readStoredValue(STORAGE_KEY_UPDATE_BALANCE);
            // Active by default; only an explicit opt-out turns it off.
            return stored === null ? true : stored === 'true';
        }
        return true;
    });

    // Manually created transactions can edit account, amount and currency both on
    // creation and afterwards. Bank-synced and imported ones keep those locked to
    // what the bank reported. A part of a split keeps them locked whatever its
    // source: changing one part's amount or account would leave the split no
    // longer adding up.
    const isSplitPartTransaction = !!transaction?.split_parent_id;
    const canEditAllFields =
        (mode === 'create' || transaction?.source === 'manually_created') &&
        !isSplitPartTransaction;

    // The date is the exception: which month a transaction counts towards is the
    // user's call, not the bank's — a payroll booked on the 27th can belong to
    // next month's budget. Parts of a split stay locked, so the parts keep
    // landing on the same day as the original.
    const canEditDate = !isSplitPartTransaction;

    // A part is our row rather than something the bank sent, so its description
    // can be renamed to say which part it is even when the original is a bank
    // transaction.
    const canEditDescription = canEditAllFields || isSplitPartTransaction;

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
            setAccountId(initialAccount?.id ?? '');
            setCategoryId('null');
            setSelectedLabelIds([]);
            setNotes('');
        }
    }, [mode, transaction, open, accounts, initialAccountId]);

    useEffect(() => {
        if (!open || !canEditAllFields) return;

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
    }, [open, canEditAllFields, accounts]);

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
        writeStoredValue(STORAGE_KEY_UPDATE_BALANCE, String(checked));
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (canEditAllFields) {
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

                const createdTransaction = await transactionSyncService.create(
                    {
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
                            finalLabelIds.length > 0
                                ? finalLabelIds
                                : undefined,
                    },
                    {
                        updateBalance: selectedAccount.banking_connection_id
                            ? false
                            : updateAccountBalance,
                    },
                );

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
                    amount?: number;
                    transaction_date?: string;
                    account_id?: string;
                    currency_code?: string;
                } = {
                    category_id: selectedCategoryId,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                };

                let finalDecryptedDescription =
                    transaction.decryptedDescription;

                const editedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                const editedCurrencyCode =
                    editedAccount?.currency_code ?? transaction.currency_code;

                if (canEditDate) {
                    updateData.transaction_date = transactionDate;
                }

                if (canEditAllFields) {
                    updateData.description = trimmedDescription;
                    updateData.description_iv = null;
                    finalDecryptedDescription = trimmedDescription;
                    updateData.amount = amount;
                    updateData.account_id = accountId;
                    updateData.currency_code = editedCurrencyCode;
                }

                const result = await transactionSyncService.update(
                    transaction.id,
                    updateData,
                    {
                        // Gate on the transaction being editable, not on the
                        // target account: the backend adjuster skips connected
                        // accounts per-account, so this still reverses the old
                        // manual account when the edit moves it onto a connected
                        // one. A moved date shifts the days in between by the
                        // amount and leaves today's balance where it was.
                        updateBalance: canEditDate
                            ? updateAccountBalance
                            : false,
                    },
                );

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
                    ...(canEditDate
                        ? {
                              transaction_date: transactionDate,
                              // The server stamps the source's own date on the
                              // first move, so the hint shows up straight away.
                              original_transaction_date:
                                  result.original_transaction_date ?? null,
                          }
                        : {}),
                    ...(canEditAllFields
                        ? {
                              amount,
                              account_id: accountId,
                              currency_code: editedCurrencyCode,
                              account: editedAccount ?? transaction.account,
                              bank: editedAccount?.bank?.id
                                  ? banks.find(
                                        (b) => b.id === editedAccount.bank?.id,
                                    )
                                  : transaction.bank,
                          }
                        : {}),
                };

                toast.success(__('Transaction updated successfully'));
                onSuccess(updatedTransaction);

                if (result.learned_rule) {
                    // The correction already taught the system a forward rule, so
                    // confirm that and offer an instant undo — and skip the
                    // "Automatize" prompt, which would only offer to create a rule
                    // that now exists. Mirrors the transaction-table flow.
                    const ruleId = result.learned_rule.id;

                    toast.success(
                        __(
                            'Learned: similar transactions will be categorized automatically.',
                        ),
                        {
                            closeButton: true,
                            duration: 10000,
                            action: {
                                label: __('Undo'),
                                onClick: () => {
                                    router.delete(destroy(ruleId).url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    });
                                },
                            },
                        },
                    );
                } else if (
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
    // An archived account stays selectable while editing a transaction that
    // already sits on it, otherwise the field reads as empty and the user cannot
    // fill it back in.
    const accountOptions =
        selectedAccount?.archived_at &&
        !transactionalAccounts.some((account) => account.id === accountId)
            ? [...transactionalAccounts, selectedAccount]
            : transactionalAccounts;

    // Where the bank or the import file dated this row, once the user has moved
    // it. Shown so the edit does not hide what actually happened.
    const originalDate =
        transaction?.original_transaction_date &&
        transaction.original_transaction_date !== transaction.transaction_date
            ? formatTransactionDate(
                  transaction.original_transaction_date,
                  locale,
              )
            : null;

    const editDescription = canEditAllFields
        ? __('Update this transaction.')
        : canEditDate
          ? __('Update the date, category and notes for this transaction.')
          : __('Update the category and notes for this transaction.');

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
                            : editDescription}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 py-4">
                        {canEditAllFields && (
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
                                        {accountOptions.map((account) => (
                                            <SelectItem
                                                key={account.id}
                                                value={String(account.id)}
                                            >
                                                {decryptedAccountNames.get(
                                                    account.id,
                                                ) || __('[Loading...]')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div className="flex flex-col gap-2">
                            <FormLabel
                                htmlFor="date"
                                className={
                                    canEditDate
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Date')}
                            </FormLabel>
                            {canEditDate ? (
                                <>
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
                                    {mode === 'edit' && (
                                        <p className="text-xs text-muted-foreground">
                                            {__(
                                                'The date decides which month and budget this transaction counts towards.',
                                            )}
                                        </p>
                                    )}
                                    {originalDate && (
                                        <p
                                            className="text-xs text-muted-foreground"
                                            data-testid="original-transaction-date"
                                        >
                                            {__('Original date: :date', {
                                                date: originalDate,
                                            })}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <div className="text-sm">
                                    {transaction &&
                                        formatTransactionDate(
                                            transaction.transaction_date,
                                            locale,
                                        )}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="description"
                                className={
                                    canEditDescription
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Description')}
                            </FormLabel>
                            {canEditDescription ? (
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
                                    canEditAllFields
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Amount')}
                            </FormLabel>
                            {canEditAllFields ? (
                                <>
                                    <AmountInput
                                        id="amount"
                                        value={amount}
                                        onChange={setAmount}
                                        currencyCode={
                                            selectedAccount?.currency_code ||
                                            'USD'
                                        }
                                        placeholder="25.00"
                                        disabled={isSubmitting}
                                        required
                                        allowNegative
                                    />

                                    {selectedAccount?.banking_connection_id ? (
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                "This account's balance comes from your bank, so it won't change.",
                                            )}
                                        </p>
                                    ) : (
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
                                    )}
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
                        {mode === 'edit' && onDelete && transaction && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    onOpenChange(false);
                                    onDelete(transaction);
                                }}
                                disabled={isSubmitting}
                                className="text-destructive hover:bg-destructive/10 hover:text-destructive sm:mr-auto dark:hover:bg-destructive/20"
                            >
                                <Trash2 />
                                {__('Delete')}
                            </Button>
                        )}
                        {mode === 'edit' &&
                            onSplit &&
                            transaction &&
                            canSplit(transaction) && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        onOpenChange(false);
                                        onSplit(transaction);
                                    }}
                                    disabled={isSubmitting}
                                >
                                    <Split />
                                    {__('Split')}
                                </Button>
                            )}
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
