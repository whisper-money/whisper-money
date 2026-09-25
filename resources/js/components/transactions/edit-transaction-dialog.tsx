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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSyncContext } from '@/contexts/sync-context';
import { useLocale } from '@/hooks/use-locale';
import { fetchJson } from '@/lib/fetch-json';
import { captureEvent } from '@/lib/posthog';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { canSplit } from '@/lib/transaction-splits';
import { appendNoteIfNotPresent, cn } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
    type CurrencyCode,
    type CurrencyOption,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { formatCurrency, toMajorUnits, toMinorUnits } from '@/utils/currency';
import { formatDate, todayDateString } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { getYear, parseISO } from 'date-fns';
import {
    CalendarDays,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    CircleDollarSign,
    CircleSlash,
    CreditCard,
    FileText,
    HelpCircle,
    Landmark,
    Lock,
    Plus,
    Split,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

export type TransactionCreateOrigin =
    | 'quick_add'
    | 'full_dialog'
    | 'account_page'
    | 'duplicate';

interface EditTransactionDialogProps {
    transaction: ServerTransaction | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: (transaction: ServerTransaction) => void;
    onCategorized?: (
        transaction: ServerTransaction,
        category: Category,
        source: 'edit_transaction_modal',
    ) => void;
    onLabelCreated?: (label: Label) => void;
    onDelete?: (transaction: ServerTransaction) => void;
    onSplit?: (transaction: ServerTransaction) => void;
    mode: 'create' | 'edit';
    initialAccountId?: string | null;
    /**
     * Create mode only: open the form filled in from this transaction, dated
     * today, for the recurring ones entered by hand.
     */
    duplicateFrom?: ServerTransaction | null;
    /** Which surface opened the dialog, for `transaction_created`. */
    origin?: TransactionCreateOrigin;
    /**
     * Reopen the dialog on a transaction that was just created, for the
     * "Change category" way out of a rule that categorized it unseen. Without
     * it the toast offers only the undo.
     */
    onRequestEdit?: (transaction: ServerTransaction) => void;
}

const STORAGE_KEY_UPDATE_BALANCE =
    'whisper_money_update_balance_on_transaction';
const STORAGE_KEY_LAST_ACCOUNT = 'whisper_money_last_transaction_account';

/**
 * The chips that stand in for the account, date and balance fields while the
 * create form is collapsed. Pill-shaped, and sized so the row reads as one
 * strip of defaults rather than three controls.
 */
const CHIP_CLASS =
    'flex h-[30px] w-fit items-center gap-1.5 rounded-full border border-input bg-muted px-2.5 text-xs font-medium text-muted-foreground shadow-none hover:bg-accent hover:text-accent-foreground';

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

/**
 * An amount converted to the account's currency at the transaction's own date,
 * the same date every total in the app converts at.
 *
 * Null whenever there is nothing to show: the two currencies match, the amount
 * is still empty, the request failed (offline), or the server has no rate for
 * that day. A figure that could not be converted is never rendered as one.
 */
function useConvertedAmount(
    amountInMinorUnits: number,
    from: CurrencyCode,
    to: CurrencyCode | undefined,
    date: string,
): number | null {
    const [converted, setConverted] = useState<number | null>(null);

    useEffect(() => {
        setConverted(null);

        if (!to || from === to || amountInMinorUnits === 0 || !date) {
            return;
        }

        let cancelled = false;
        const params = new URLSearchParams({
            from,
            to,
            date,
            amount: String(amountInMinorUnits),
        });

        fetchJson<{ amount: number | null }>(`/api/exchange-rate?${params}`)
            .then((json) => {
                if (!cancelled) {
                    setConverted(json.amount);
                }
            })
            .catch(() => {
                // Offline or a rate we could not reach: the original alone.
            });

        return () => {
            cancelled = true;
        };
    }, [amountInMinorUnits, from, to, date]);

    return converted;
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
    duplicateFrom = null,
    origin = 'full_dialog',
    onRequestEdit,
}: EditTransactionDialogProps) {
    const locale = useLocale();
    const { auth, currencies } = usePage<SharedData>().props;
    const userCurrencyCode = auth.user.currency_code;

    const { sync } = useSyncContext();
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [unsignedAmount, setUnsignedAmount] = useState<number>(0);
    const [transactionType, setTransactionType] = useState<
        'expense' | 'income'
    >('expense');
    const [showNotes, setShowNotes] = useState(false);
    // Collapsed on every open, never remembered: how often the reader reaches
    // for "More options" is the measurement, and a sticky expansion would
    // answer it for them. `transaction_created.expanded` carries the answer.
    const [expanded, setExpanded] = useState(false);
    const [showDateField, setShowDateField] = useState(false);
    // What the chips were filled with when the dialog opened, so a correction
    // is told apart from a default that was already right. They deliberately
    // survive "Save and add another": a default that was wrong for the first
    // entry of a batch was wrong for the rest of it too.
    const defaultAccountId = useRef('');
    const defaultDate = useRef('');
    const defaultCategoryId = useRef('null');
    // Only the first save is the copy: what "Save and add another" brings next
    // is a transaction of its own, so rules and analytics treat it as one.
    const isDuplicate = useRef(false);
    const amountInputRef = useRef<HTMLInputElement>(null);
    const [focusAmountAfterSave, setFocusAmountAfterSave] = useState(false);
    const [accountId, setAccountId] = useState<string>('');
    const [currencyCode, setCurrencyCode] =
        useState<CurrencyCode>(userCurrencyCode);
    const [categoryId, setCategoryId] = useState<string>('null');
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
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
        function fillFrom(source: ServerTransaction) {
            setDescription(source.description);
            setUnsignedAmount(Math.abs(source.amount));
            setTransactionType(source.amount > 0 ? 'income' : 'expense');
            setCurrencyCode(source.currency_code);
            setCategoryId(source.category_id || 'null');
            setSelectedLabelIds(
                source.label_ids || source.labels?.map((l) => l.id) || [],
            );
            setNotes(source.notes || '');
            setShowNotes(!!source.notes);
        }

        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setAccountId(transaction.account_id);
            fillFrom(transaction);
        } else if (mode === 'create' && open) {
            const today = todayDateString();
            setTransactionDate(today);
            setDescription('');
            setUnsignedAmount(0);
            setTransactionType('expense');
            setShowNotes(false);
            setExpanded(false);
            setShowDateField(false);
            defaultDate.current = today;
            const availableAccounts = filterTransactionalAccounts(accounts);
            // The chip always opens filled in: the duplicated transaction's
            // account wins, then the account being read, then the one the last
            // manual transaction went to, then simply the first. Pre-filling is
            // fine because the chip shows what it picked — it is hiding it
            // that would not be.
            const initialAccount =
                availableAccounts.find(
                    (account) => account.id === duplicateFrom?.account_id,
                ) ??
                availableAccounts.find(
                    (account) => account.id === initialAccountId,
                ) ??
                availableAccounts.find(
                    (account) =>
                        account.id ===
                        readStoredValue(STORAGE_KEY_LAST_ACCOUNT),
                ) ??
                availableAccounts[0];
            setAccountId(initialAccount?.id ?? '');
            defaultAccountId.current = initialAccount?.id ?? '';
            setCurrencyCode(initialAccount?.currency_code ?? userCurrencyCode);
            setCategoryId('null');
            setSelectedLabelIds([]);
            setNotes('');
            if (duplicateFrom) {
                fillFrom(duplicateFrom);
            }
            isDuplicate.current = !!duplicateFrom;
            defaultCategoryId.current = duplicateFrom?.category_id || 'null';
        }
    }, [
        mode,
        transaction,
        open,
        accounts,
        initialAccountId,
        userCurrencyCode,
        duplicateFrom,
    ]);

    useEffect(() => {
        if (!focusAmountAfterSave || isSubmitting) {
            return;
        }

        // The amount is disabled while the save is in flight, and focusing a
        // disabled input does nothing, so this waits for the render that
        // enables it again.
        amountInputRef.current?.focus();
        setFocusAmountAfterSave(false);
    }, [focusAmountAfterSave, isSubmitting]);

    function checkAndApplyAutomationRules() {
        // A duplicate already carries the category, labels and notes the user
        // settled on for the original, so a rule has nothing left to decide.
        if (
            mode !== 'create' ||
            isDuplicate.current ||
            automationRules.length === 0
        ) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                ruleName: null,
            };
        }

        const result = evaluateRulesForNewTransaction(
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
        );

        if (!result) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                ruleName: null,
            };
        }

        let finalNotes = notes.trim();

        if (result.note) {
            finalNotes = appendNoteIfNotPresent(
                finalNotes || undefined,
                result.note,
            );
        }

        return {
            categoryId: result.categoryId,
            labelIds: result.labelIds || [],
            matchedLabels: result.labels || [],
            notes: finalNotes || null,
            ruleName: result.rule.title,
        };
    }

    /**
     * Keep the amount the user typed at face value when the scale changes:
     * 10.50 EUR becomes 10.50 BTC, not 0.00001050 of one.
     */
    function handleCurrencyChange(nextCurrencyCode: CurrencyCode) {
        setUnsignedAmount((current) =>
            toMinorUnits(toMajorUnits(current, currencyCode), nextCurrencyCode),
        );
        setCurrencyCode(nextCurrencyCode);
    }

    /**
     * The currency follows the account: picking an account is the clearest
     * statement of what the transaction is in, so it wins over an earlier pick
     * in the currency field. Changing only the currency afterwards keeps it.
     */
    function handleAccountChange(nextAccountId: string) {
        setAccountId(nextAccountId);

        const nextCurrencyCode = accounts.find(
            (account) => account.id === nextAccountId,
        )?.currency_code;

        if (nextCurrencyCode) {
            handleCurrencyChange(nextCurrencyCode);
        }
    }

    function handleUpdateBalanceChange(checked: boolean) {
        setUpdateAccountBalance(checked);
        writeStoredValue(STORAGE_KEY_UPDATE_BALANCE, String(checked));
    }

    /**
     * A rule filled the category in while those fields were hidden, so the
     * toast names the category it picked and carries both ways out of it.
     */
    function notifyRuleCategorized(
        created: ServerTransaction,
        category: Category,
        balanceWasUpdated: boolean,
    ) {
        toast.success(__('Transaction saved'), {
            description: __('A rule categorized it as :category', {
                category: category.name,
            }),
            closeButton: true,
            duration: 10000,
            ...(onRequestEdit
                ? {
                      action: {
                          label: __('Change category'),
                          onClick: () => onRequestEdit(created),
                      },
                  }
                : {}),
            cancel: {
                label: __('Undo'),
                onClick: async () => {
                    await transactionSyncService.delete(created.id, {
                        updateBalance: balanceWasUpdated,
                    });
                    sync();
                    refreshPageAfterWrite();
                },
            },
        });
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        await save(false);
    }

    /**
     * `addAnother` keeps the dialog open for the next entry: the account, date
     * and type carry over, the amount and the prose do not.
     */
    async function save(addAnother: boolean) {
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
                const ruleResult = checkAndApplyAutomationRules();

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
                const finalNotesValue = finalNotes || null;

                const selectedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                if (!selectedAccount) {
                    throw new Error(__('Selected account not found'));
                }

                // A connected account's balance is the bank's to report, so
                // the undo below must not try to reverse one either.
                const balanceWasUpdated = selectedAccount.banking_connection_id
                    ? false
                    : updateAccountBalance;

                const createdTransaction = await transactionSyncService.create(
                    {
                        user_id: '00000000-0000-0000-0000-000000000000',
                        account_id: accountId,
                        category_id: finalCategoryId,
                        description: finalDescription,
                        transaction_date: transactionDate,
                        amount: signedAmount,
                        currency_code: currencyCode,
                        notes: finalNotesValue,
                        creditor_name: null,
                        debtor_name: null,
                        source: 'manually_created' as const,
                        label_ids:
                            finalLabelIds.length > 0
                                ? finalLabelIds
                                : undefined,
                    },
                    { updateBalance: balanceWasUpdated },
                );

                const updatedCategory = finalCategoryId
                    ? categories.find(
                          (category) => category.id === finalCategoryId,
                      ) || null
                    : null;

                const transactionLabels = labels.filter((l) =>
                    finalLabelIds.includes(l.id),
                );

                const newTransaction: ServerTransaction = {
                    ...createdTransaction,
                    description: trimmedDescription,
                    notes: finalNotes || null,
                    category: updatedCategory,
                    account: selectedAccount,
                    bank: selectedAccount.bank?.id
                        ? banks.find((b) => b.id === selectedAccount.bank?.id)
                        : undefined,
                    labels: transactionLabels,
                    label_ids: finalLabelIds,
                };

                // The rule only wrote the category when the user had left it
                // alone, which is the case the collapsed form hides.
                const ruleAppliedCategory =
                    ruleResult.categoryId !== null && categoryId === 'null';

                if (!expanded && ruleAppliedCategory && updatedCategory) {
                    notifyRuleCategorized(
                        newTransaction,
                        updatedCategory,
                        balanceWasUpdated,
                    );
                } else {
                    toast.success(__('Transaction created successfully'));
                    if (ruleResult.ruleName) {
                        toast.success(
                            __('Rule ":rule" applied', {
                                rule: ruleResult.ruleName,
                            }),
                        );
                    }
                }

                writeStoredValue(STORAGE_KEY_LAST_ACCOUNT, accountId);

                captureEvent('transaction_created', {
                    source: 'manually_created',
                    origin: isDuplicate.current ? 'duplicate' : origin,
                    expanded,
                    saved_and_added_another: addAnother,
                    account_chip_changed:
                        accountId !== defaultAccountId.current,
                    date_chip_changed: transactionDate !== defaultDate.current,
                    // The chip opens on Uncategorized, or on the duplicated
                    // transaction's category, so anything else is the user's
                    // own pick.
                    category_chip_changed:
                        categoryId !== defaultCategoryId.current,
                    rule_applied_category: ruleAppliedCategory,
                });

                onSuccess(newTransaction);

                isDuplicate.current = false;

                if (addAnother) {
                    setUnsignedAmount(0);
                    setDescription('');
                    // The prose is about this transaction, not the next one;
                    // the category is a bucket a batch tends to share.
                    setNotes('');
                    setShowNotes(false);
                    setFocusAmountAfterSave(true);
                } else {
                    onOpenChange(false);
                }

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

                const updateData: {
                    category_id: string | null;
                    notes: string | null;
                    description?: string;
                    label_ids?: string[];
                    amount?: number;
                    transaction_date?: string;
                    account_id?: string;
                    currency_code?: string;
                } = {
                    category_id: selectedCategoryId,
                    notes: trimmedNotes || null,
                    label_ids: selectedLabelIds,
                };

                const editedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );

                if (canEditDate) {
                    updateData.transaction_date = transactionDate;
                }

                if (canEditDescription) {
                    updateData.description = trimmedDescription;
                }

                if (canEditAllFields) {
                    updateData.amount = signedAmount;
                    updateData.account_id = accountId;
                    updateData.currency_code = currencyCode;
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

                const updatedTransaction: ServerTransaction = {
                    ...transaction,
                    category_id: selectedCategoryId,
                    category: updatedCategory,
                    description:
                        updateData.description ?? transaction.description,
                    notes: trimmedNotes || null,
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
                              currency_code: currencyCode,
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
    // A new transaction opens on the amount, the description and three chips;
    // everything else waits behind "More options". Editing is untouched.
    const isMinimal = mode === 'create' && !expanded;
    const transactionalAccounts = filterTransactionalAccounts(accounts);
    // An archived account stays selectable while editing a transaction that
    // already sits on it, otherwise the field reads as empty and the user cannot
    // fill it back in.
    const accountOptions =
        selectedAccount?.archived_at &&
        !transactionalAccounts.some((account) => account.id === accountId)
            ? [...transactionalAccounts, selectedAccount]
            : transactionalAccounts;

    // A transaction can hold a currency the picker no longer offers - an imported
    // row, a code since retired - and it stays selectable so the field never
    // reads as empty.
    const currencyOptions: CurrencyOption[] = currencies.accounts.some(
        (currency) => currency.code === currencyCode,
    )
        ? currencies.accounts
        : [...currencies.accounts, { code: currencyCode, name: currencyCode }];

    // Sits inside the amount field, so the label the field already carries is
    // its label too - hence the aria-label rather than a <FormLabel> of its own.
    const currencyPicker = (
        <Select
            name="currency_code"
            value={currencyCode}
            onValueChange={handleCurrencyChange}
            disabled={isSubmitting}
        >
            <SelectTrigger
                id="currency"
                aria-label={__('Currency')}
                data-testid="currency-select"
                className="h-7 w-fit gap-1 rounded-md px-2 text-xs font-medium shadow-none"
            >
                <SelectValue placeholder={__('Select currency')}>
                    {currencyCode}
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                {currencyOptions.map((currency) => (
                    <SelectItem key={currency.code} value={currency.code}>
                        {`${currency.code} - ${currency.name}`}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

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
        ? accounts.find((account) => account.id === transaction.account_id)
              ?.name
        : undefined;

    const headerCategory =
        categoryId !== 'null'
            ? (categories.find((category) => category.id === categoryId) ??
              null)
            : null;

    const formattedAmount = transaction
        ? formatCurrency(transaction.amount, transaction.currency_code, locale)
        : '';

    // The editable branch mirrors the input, which is unsigned - the toggle owns
    // the sign - while the read-only one mirrors the signed amount it prints.
    const convertedAmount = useConvertedAmount(
        canEditAllFields ? unsignedAmount : (transaction?.amount ?? 0),
        currencyCode,
        selectedAccount?.currency_code,
        transactionDate,
    );
    const formattedConvertedAmount =
        convertedAmount === null || !selectedAccount
            ? null
            : formatCurrency(
                  convertedAmount,
                  selectedAccount.currency_code,
                  locale,
              );

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
              formattedConvertedAmount
                  ? {
                        label: __('In account currency'),
                        value: formattedConvertedAmount,
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

    const splitRow = mode === 'edit' &&
        onSplit &&
        transaction &&
        canSplit(transaction) && (
            <button
                type="button"
                onClick={() => {
                    onOpenChange(false);
                    onSplit(transaction);
                }}
                disabled={isSubmitting}
                data-testid="split-transaction"
                className="flex w-full items-center gap-3 rounded-md border px-3 py-2.5 text-left text-sm transition-colors outline-none hover:bg-accent focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-accent/50"
            >
                <Split className="size-4 shrink-0" />
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="font-medium">
                        {__('Split into parts')}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {__('Divide :amount across categories', {
                            amount: formattedAmount,
                        })}
                    </span>
                </span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            </button>
        );

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
                autoFocus={showDateField}
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

    const accountSelectItems = accountOptions.map((account) => (
        <SelectItem key={account.id} value={String(account.id)}>
            {`${account.name} · ${account.currency_code}`}
        </SelectItem>
    ));

    const accountField = (
        <div className="space-y-2">
            <FormLabel htmlFor="account">{__('Account')}</FormLabel>
            <Select
                value={accountId}
                onValueChange={handleAccountChange}
                disabled={isSubmitting}
            >
                <SelectTrigger id="account" data-testid="account-select">
                    <SelectValue placeholder={__('Select account')} />
                </SelectTrigger>
                <SelectContent>{accountSelectItems}</SelectContent>
            </Select>
        </div>
    );

    // Empty until the open effect has run, and a date-fns format of that
    // throws rather than rendering.
    const dateChipLabel =
        !transactionDate || transactionDate === todayDateString()
            ? __('Today')
            : formatTransactionDate(transactionDate, locale);

    // The collapsed form's defaults. The account and category ones are the
    // select itself wearing a pill, so picking from them stays one tap.
    const chipsRow = (
        <div className="flex flex-wrap items-center gap-2">
            <Select
                value={accountId}
                onValueChange={handleAccountChange}
                disabled={isSubmitting}
            >
                <SelectTrigger
                    aria-label={__('Account')}
                    data-testid="account-chip"
                    className={cn(
                        CHIP_CLASS,
                        "[&_svg:not([class*='size-'])]:size-3.5",
                    )}
                >
                    <CreditCard />
                    <SelectValue placeholder={__('Select account')}>
                        {accounts.find((account) => account.id === accountId)
                            ?.name ?? __('Select account')}
                    </SelectValue>
                </SelectTrigger>
                <SelectContent>{accountSelectItems}</SelectContent>
            </Select>

            <Button
                type="button"
                variant="outline"
                className={CHIP_CLASS}
                onClick={() => setShowDateField(true)}
                aria-expanded={showDateField}
                disabled={isSubmitting}
                data-testid="date-chip"
            >
                <CalendarDays className="size-3.5" />
                {dateChipLabel}
                {showDateField ? (
                    <ChevronUp className="size-3.5" />
                ) : (
                    <ChevronDown className="size-3.5" />
                )}
            </Button>

            {/* Deliberately opens on Uncategorized: a pre-filled category
                would count as the user's own pick and switch the automation
                rules' categorization off for every hand-typed transaction. */}
            <CategorySelect
                value={categoryId}
                onValueChange={setCategoryId}
                categories={categories}
                disabled={isSubmitting}
                placeholder={__('Uncategorized')}
                // Capped so a long category name truncates inside the pill
                // instead of stretching it into a full-width bar.
                triggerClassName={cn(CHIP_CLASS, 'max-w-40')}
                data-testid="category-chip"
            />

            {/* The bank owns a connected account's balance, so there is
                nothing here to switch off. */}
            {!selectedAccount?.banking_connection_id && (
                <Button
                    type="button"
                    variant="outline"
                    className={cn(
                        CHIP_CLASS,
                        // Readable without relying on the colour: struck-through
                        // icon and a dashed edge when it is off.
                        !updateAccountBalance && 'border-dashed bg-transparent',
                    )}
                    aria-pressed={updateAccountBalance}
                    onClick={() =>
                        handleUpdateBalanceChange(!updateAccountBalance)
                    }
                    disabled={isSubmitting}
                    data-testid="balance-chip"
                >
                    {updateAccountBalance ? (
                        <CircleDollarSign className="size-3.5" />
                    ) : (
                        <CircleSlash className="size-3.5" />
                    )}
                    {updateAccountBalance
                        ? __('Updates the balance')
                        : __('Leaves the balance alone')}
                </Button>
            )}
        </div>
    );

    // The collapsed form says this with the balance chip instead.
    const balanceControl = selectedAccount?.banking_connection_id ? (
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
                    handleUpdateBalanceChange(checked === true)
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
    );

    const moreOptionsTrigger = (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="-ml-2 w-fit px-2 text-muted-foreground"
            aria-expanded={expanded}
            onClick={() => setExpanded((current) => !current)}
            disabled={isSubmitting}
            data-testid="toggle-more-options"
        >
            {expanded ? <ChevronUp /> : <ChevronDown />}
            {expanded ? __('Fewer options') : __('More options')}
        </Button>
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
            <DialogContent
                className="focus:outline-none sm:max-w-[525px]"
                // On a read-only transaction the split row is the first
                // tabbable element, and landing on it opened the dialog with
                // it ringed as if selected. Focus the dialog itself instead;
                // Tab still reaches the row.
                onOpenAutoFocus={(event) => {
                    if (splitRow && !canEditAllFields) {
                        event.preventDefault();
                        (event.currentTarget as HTMLElement).focus();
                    }
                }}
            >
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
                                    {/* Side by side is too tight for the
                                        number on a phone: the toggle, the
                                        currency picker and the amount cannot
                                        share 390px without clipping it, so
                                        they stack until there is room. */}
                                    <div className="flex flex-col items-stretch gap-3 sm:flex-row">
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            className="w-full sm:w-fit"
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
                                                className="h-11 flex-1 px-4"
                                                data-testid="transaction-type-expense"
                                            >
                                                {__('Expense')}
                                            </ToggleGroupItem>
                                            <ToggleGroupItem
                                                value="income"
                                                className="h-11 flex-1 px-4"
                                                data-testid="transaction-type-income"
                                            >
                                                {__('Income')}
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                        <div className="flex-1">
                                            <AmountInput
                                                id="amount"
                                                ref={amountInputRef}
                                                value={unsignedAmount}
                                                // A typed minus sign still parses negative even
                                                // without allowNegative; the toggle owns the sign.
                                                onChange={(cents) =>
                                                    setUnsignedAmount(
                                                        Math.abs(cents),
                                                    )
                                                }
                                                currencyCode={currencyCode}
                                                currencySlot={currencyPicker}
                                                disabled={isSubmitting}
                                                required
                                                className="h-11 text-right text-xl font-semibold tabular-nums md:text-xl"
                                            />
                                        </div>
                                    </div>
                                    {formattedConvertedAmount && (
                                        <p
                                            className="text-sm text-muted-foreground"
                                            data-testid="converted-amount"
                                        >
                                            {__(
                                                '≈ :amount in the account currency',
                                                {
                                                    amount: formattedConvertedAmount,
                                                },
                                            )}
                                        </p>
                                    )}
                                    {!isMinimal && balanceControl}
                                </div>

                                {splitRow}

                                {descriptionField}

                                {isMinimal ? (
                                    <>
                                        {chipsRow}
                                        {showDateField && dateField}
                                    </>
                                ) : (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {dateField}
                                        {accountField}
                                    </div>
                                )}
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

                                    {splitRow}

                                    {canEditDate && dateField}

                                    {canEditDescription && descriptionField}
                                </>
                            )
                        )}

                        {!isMinimal && organizeFields}

                        {!isMinimal && notesField}

                        {mode === 'create' && moreOptionsTrigger}
                    </div>

                    {/* One row on every width in edit mode: the dialog's X,
                        Esc and tapping outside already close it, so there is
                        no Cancel to stack. */}
                    <DialogFooter className={cn(mode === 'edit' && 'flex-row')}>
                        {mode === 'edit' && onDelete && transaction && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={() => {
                                            onOpenChange(false);
                                            onDelete(transaction);
                                        }}
                                        disabled={isSubmitting}
                                        aria-label={__('Delete')}
                                        className="shrink-0"
                                    >
                                        <Trash2 className="text-destructive" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{__('Delete')}</TooltipContent>
                            </Tooltip>
                        )}
                        {mode === 'create' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => save(true)}
                                disabled={isSubmitting}
                                data-testid="submit-and-add-another"
                            >
                                {__('Save and add another')}
                            </Button>
                        )}
                        <Button
                            type="submit"
                            disabled={isSubmitting}
                            data-testid="submit-transaction"
                            className={cn(
                                mode === 'edit' && 'flex-1 sm:flex-none',
                            )}
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
