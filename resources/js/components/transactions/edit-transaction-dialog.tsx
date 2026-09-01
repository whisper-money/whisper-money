import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
import { AmountInput } from '@/components/ui/amount-input';
import { Badge } from '@/components/ui/badge';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useSyncContext } from '@/contexts/sync-context';
import { useLocale } from '@/hooks/use-locale';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { canSplit } from '@/lib/transaction-splits';
import { appendNoteIfNotPresent } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatCurrency, toMajorUnits } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { getYear, parseISO } from 'date-fns';
import {
    FileText,
    HelpCircle,
    Landmark,
    Lock,
    Plus,
    Split,
    Trash2,
} from 'lucide-react';
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
    const userCurrencyCode =
        usePage<SharedData>().props.auth.user.currency_code;
    const STORAGE_KEY_UPDATE_BALANCE =
        'whisper_money_update_balance_on_transaction';

    const { sync } = useSyncContext();
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [unsignedAmount, setUnsignedAmount] = useState<number>(0);
    const [transactionType, setTransactionType] = useState<
        'expense' | 'income'
    >('expense');
    const [showNotes, setShowNotes] = useState(false);
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

    const signedAmount =
        transactionType === 'income' ? unsignedAmount : -unsignedAmount;

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setUnsignedAmount(Math.abs(transaction.amount));
            setTransactionType(transaction.amount > 0 ? 'income' : 'expense');
            setAccountId(transaction.account_id);
            setCategoryId(transaction.category_id || 'null');
            setSelectedLabelIds(
                transaction.label_ids ||
                    transaction.labels?.map((l) => l.id) ||
                    [],
            );
            setNotes(transaction.decryptedNotes || '');
            setShowNotes(!!transaction.decryptedNotes);
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setUnsignedAmount(0);
            setTransactionType('expense');
            setShowNotes(false);
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
        if (!open) return;

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
    }, [open, accounts]);

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
                amount: toMajorUnits(
                    signedAmount,
                    accounts.find((acc) => acc.id === accountId)
                        ?.currency_code ?? userCurrencyCode,
                ),
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

        if (canEditDescription && !description.trim()) {
            toast.error(__('Description is required'));
            return;
        }

        if (canEditAllFields) {
            if (unsignedAmount === 0) {
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
                        amount: signedAmount,
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

                if (canEditDescription) {
                    updateData.description = trimmedDescription;
                    updateData.description_iv = null;
                    finalDecryptedDescription = trimmedDescription;
                }

                if (canEditAllFields) {
                    updateData.amount = signedAmount;
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
                              source_date: result.source_date ?? null,
                          }
                        : {}),
                    ...(canEditAllFields
                        ? {
                              amount: signedAmount,
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

    // The date the source gave this row: the stored one once it has been moved,
    // otherwise the day it still sits on. Manual rows have no source to compare
    // against - the user picked every date they ever had.
    const sourceDate =
        transaction && transaction.source !== 'manually_created'
            ? (transaction.source_date ?? transaction.transaction_date)
            : null;

    // Compared against the field rather than against the saved row, so it shows
    // the moment the user types a different date instead of only after saving -
    // which is when knowing what the source said is worth something. Moving the
    // date back onto the source's own day hides it again.
    const movedFromSourceDate =
        sourceDate && sourceDate !== transactionDate
            ? formatTransactionDate(sourceDate, locale)
            : null;

    const editDescription = canEditAllFields
        ? __('Update this transaction.')
        : canEditDate
          ? __('Update the date, category and notes for this transaction.')
          : __('Update the category and notes for this transaction.');

    const accountName = transaction
        ? decryptedAccountNames.get(transaction.account_id)
        : undefined;

    const headerCategory =
        categoryId !== 'null'
            ? (categories.find((category) => category.id === categoryId) ??
              null)
            : null;

    const formattedAmount = transaction
        ? formatCurrency(transaction.amount, transaction.currency_code, locale)
        : '';

    const detailRows = transaction
        ? [
              transaction.creditor_name
                  ? { label: __('Creditor'), value: transaction.creditor_name }
                  : null,
              transaction.debtor_name
                  ? { label: __('Debtor'), value: transaction.debtor_name }
                  : null,
              canEditDate
                  ? null
                  : {
                        label: __('Date'),
                        value: formatTransactionDate(
                            transaction.transaction_date,
                            locale,
                        ),
                    },
              accountName
                  ? {
                        label: __('Account'),
                        value: transaction.bank?.name
                            ? `${accountName} · ${transaction.bank.name}`
                            : accountName,
                    }
                  : null,
          ].filter((row): row is { label: string; value: string } => !!row)
        : [];

    const sourceLabel = isSplitPartTransaction
        ? __('Part of a split transaction')
        : transaction?.source === 'imported'
          ? __('Imported from a file')
          : __('Imported from your bank');
    const SourceIcon = isSplitPartTransaction
        ? Split
        : transaction?.source === 'imported'
          ? FileText
          : Landmark;

    const descriptionField = (
        <div className="space-y-2">
            <FormLabel htmlFor="description">{__('Description')}</FormLabel>
            <Input
                id="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder={__('Transaction description')}
                disabled={isSubmitting}
                required
            />
        </div>
    );

    const dateField = (
        <div className="space-y-2">
            <FormLabel htmlFor="date">{__('Date')}</FormLabel>
            <Input
                id="date"
                type="date"
                value={transactionDate}
                onChange={(e) => setTransactionDate(e.target.value)}
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
            {movedFromSourceDate && (
                <p
                    className="text-xs text-muted-foreground"
                    data-testid="original-transaction-date"
                >
                    {__('Original date: :date', {
                        date: movedFromSourceDate,
                    })}
                </p>
            )}
        </div>
    );

    const organizeFields = (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
                <FormLabel htmlFor="category">{__('Category')}</FormLabel>
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
        </div>
    );

    const notesField = showNotes ? (
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
    ) : (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="-ml-2 w-fit px-2 text-muted-foreground"
            onClick={() => setShowNotes(true)}
            disabled={isSubmitting}
        >
            <Plus />
            {__('Add note')}
        </Button>
    );

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
                        {canEditAllFields ? (
                            <>
                                <div className="space-y-2">
                                    <FormLabel htmlFor="amount">
                                        {__('Amount')}
                                    </FormLabel>
                                    <div className="flex items-stretch gap-3">
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            value={transactionType}
                                            onValueChange={(value) => {
                                                if (value) {
                                                    setTransactionType(
                                                        value as
                                                            | 'expense'
                                                            | 'income',
                                                    );
                                                }
                                            }}
                                            disabled={isSubmitting}
                                        >
                                            <ToggleGroupItem
                                                value="expense"
                                                className="h-11 px-4"
                                                data-testid="transaction-type-expense"
                                            >
                                                {__('Expense')}
                                            </ToggleGroupItem>
                                            <ToggleGroupItem
                                                value="income"
                                                className="h-11 px-4"
                                                data-testid="transaction-type-income"
                                            >
                                                {__('Income')}
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                        <div className="flex-1">
                                            <AmountInput
                                                id="amount"
                                                value={unsignedAmount}
                                                // A typed minus sign still parses negative even
                                                // without allowNegative; the toggle owns the sign.
                                                onChange={(cents) =>
                                                    setUnsignedAmount(
                                                        Math.abs(cents),
                                                    )
                                                }
                                                currencyCode={
                                                    selectedAccount?.currency_code ||
                                                    userCurrencyCode
                                                }
                                                disabled={isSubmitting}
                                                required
                                                className="h-11 text-right text-xl font-semibold tabular-nums md:text-xl"
                                            />
                                        </div>
                                    </div>
                                    {selectedAccount?.banking_connection_id ? (
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                "This account's balance comes from your bank, so it won't change.",
                                            )}
                                        </p>
                                    ) : (
                                        <div className="flex items-center gap-2 pt-1">
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
                                                className="cursor-pointer font-normal text-muted-foreground"
                                            >
                                                {__('Update account balance')}
                                            </FormLabel>
                                        </div>
                                    )}
                                </div>

                                {descriptionField}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {dateField}
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
                                                    placeholder={__(
                                                        'Select account',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {accountOptions.map(
                                                    (account) => (
                                                        <SelectItem
                                                            key={account.id}
                                                            value={String(
                                                                account.id,
                                                            )}
                                                        >
                                                            {`${decryptedAccountNames.get(account.id) || __('[Loading...]')} · ${account.currency_code}`}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </>
                        ) : (
                            transaction && (
                                <>
                                    <div className="space-y-2">
                                        {/* The concept is the one thing that
                                            identifies the transaction, so it
                                            gets the full width and wraps -
                                            bank descriptions are long and an
                                            ellipsis hid the useful half. */}
                                        <div
                                            className="font-medium break-words"
                                            data-testid="transaction-header-description"
                                        >
                                            {description}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {headerCategory ? (
                                                <CategoryIcon
                                                    category={headerCategory}
                                                    className="p-1.5"
                                                />
                                            ) : (
                                                <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                    <HelpCircle className="size-3.5 text-zinc-500" />
                                                </div>
                                            )}
                                            <div className="min-w-0 flex-1 text-sm text-muted-foreground">
                                                {formatTransactionDate(
                                                    transaction.transaction_date,
                                                    locale,
                                                )}
                                                {accountName
                                                    ? ` · ${accountName}`
                                                    : ''}
                                            </div>
                                            <div className="shrink-0 text-2xl font-semibold tabular-nums">
                                                {formattedAmount}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <div className="rounded-md border">
                                            {detailRows.map((row) => (
                                                <div
                                                    key={row.label}
                                                    className="flex items-center justify-between gap-4 border-b px-3 py-2.5 text-sm"
                                                >
                                                    <span className="text-muted-foreground">
                                                        {row.label}
                                                    </span>
                                                    <span className="truncate text-right">
                                                        {row.value}
                                                    </span>
                                                </div>
                                            ))}
                                            <div className="flex items-center justify-between gap-4 px-3 py-2.5 text-sm">
                                                <span className="text-muted-foreground">
                                                    {__('Source')}
                                                </span>
                                                <Badge variant="secondary">
                                                    <SourceIcon />
                                                    {sourceLabel}
                                                </Badge>
                                            </div>
                                        </div>
                                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <Lock className="size-3" />
                                            {__(
                                                'These details cannot be edited.',
                                            )}
                                        </p>
                                    </div>

                                    {canEditDate && dateField}

                                    {canEditDescription && descriptionField}
                                </>
                            )
                        )}

                        {organizeFields}

                        {notesField}
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
