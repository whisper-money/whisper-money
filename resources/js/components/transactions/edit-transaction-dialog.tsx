import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { Shortcut } from '@/components/shortcuts/shortcut';
import { ShortcutKbd } from '@/components/shortcuts/shortcut-kbd';
import { CategorySelect } from '@/components/transactions/category-select';
import {
    buildSplitBreakdown,
    SplitBreakdown,
} from '@/components/transactions/split-breakdown';
import { TransactionRowDropdownMenuContent } from '@/components/transactions/transaction-row-dropdown-menu';
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
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { ownsBalanceUpdates } from '@/hooks/use-owns-balance-updates';
import { useShortcutHint } from '@/hooks/use-shortcut';
import { decrypt, importKey } from '@/lib/crypto';
import { fetchJson } from '@/lib/fetch-json';
import { getStoredKey } from '@/lib/key-storage';
import { captureEvent } from '@/lib/posthog';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import {
    getTransactionRowActions,
    type TransactionRowActionHandlers,
} from '@/lib/transaction-row-actions';
import {
    canSplit,
    hasLegacySplitLines,
    isSplitPart,
} from '@/lib/transaction-splits';
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
import { type DecryptedTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { formatDate, todayDateString } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { getYear, isValid, parseISO } from 'date-fns';
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
    MoreHorizontal,
    Plus,
    Split,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { toast } from 'sonner';

export type TransactionCreateOrigin =
    | 'quick_add'
    | 'full_dialog'
    | 'account_page'
    | 'header'
    | 'duplicate';

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
    /**
     * Edit mode: offers "Split into parts", which closes this dialog and opens
     * the split one. Left out while splitting is off.
     */
    onSplit?: (transaction: DecryptedTransaction) => void;
    /**
     * Edit mode: the row menu's actions, offered in the header on a phone,
     * whose compact list has no row menu to reach them from.
     */
    rowActions?: TransactionRowActionHandlers;
    mode: 'create' | 'edit';
    initialAccountId?: string | null;
    /**
     * Create mode only: open the form filled in from this transaction, dated
     * today, for the recurring ones entered by hand. A split is never copied:
     * the copy goes out whole, and can be split on its own.
     */
    duplicateFrom?: DecryptedTransaction | null;
    /** Which surface opened the dialog, for `transaction_created`. */
    origin?: TransactionCreateOrigin;
    /**
     * Reopen the dialog on a transaction that was just created, for the
     * "Change category" way out of a rule that categorized it unseen. Without
     * it the toast offers only the undo.
     */
    onRequestEdit?: (transaction: DecryptedTransaction) => void;
}

const STORAGE_KEY_UPDATE_BALANCE =
    'whisper_money_update_balance_on_transaction';
const STORAGE_KEY_LAST_ACCOUNT = 'whisper_money_last_transaction_account';

/**
 * The chips that stand in for the account, date, category and balance fields
 * while the create form is collapsed. Pill-shaped, and sized so the row reads
 * as one strip of defaults rather than separate controls.
 */
const CHIP_CLASS =
    'flex h-[30px] w-fit items-center gap-1.5 rounded-full border border-input bg-muted px-2.5 text-xs font-medium text-muted-foreground shadow-none hover:bg-accent hover:text-accent-foreground';

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

/**
 * A date as the dialog shows it in plain text (the "original date" hint and
 * the date chip): the year is dropped when it is the current one, and the
 * month name is capitalized for the locales that put it first in lowercase.
 * A value parseISO cannot read is shown as it came.
 */
function formatSourceDate(date: string, locale: string): string {
    const parsed = parseISO(date);

    if (!isValid(parsed)) {
        return date;
    }

    const formatted = formatDate(
        parsed,
        getYear(parsed) === getYear(new Date()) ? 'MMMM d' : 'MMMM d, yyyy',
        locale,
    );

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
    rowActions,
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
    const formRef = useRef<HTMLFormElement>(null);
    const notesRef = useRef<HTMLTextAreaElement>(null);
    const saveShortcut = useShortcutHint('transaction-dialog.save');
    const addNoteShortcut = useShortcutHint('transaction-dialog.add-note');
    const [focusAmountAfterSave, setFocusAmountAfterSave] = useState(false);
    const [accountId, setAccountId] = useState<string>('');
    const [currencyCode, setCurrencyCode] =
        useState<CurrencyCode>(userCurrencyCode);
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

    // Manually created transactions can edit account, amount and description
    // both on creation and afterwards. Bank-synced and imported ones keep those
    // locked to what the source reported. A part of a split keeps them locked
    // whatever its source: changing one part's amount or account would leave
    // the split no longer adding up (the server drops those fields anyway).
    const isSplitPartTransaction =
        mode === 'edit' && !!transaction && isSplitPart(transaction);
    const canEditAllFields =
        (mode === 'create' || transaction?.source === 'manually_created') &&
        !isSplitPartTransaction;

    // Parts of a split stay on the original's day, so their date is locked
    // too; every other transaction can be moved (see sourceDate below).
    const canEditDate = !isSplitPartTransaction;

    // A part is our row rather than something the bank sent, so its
    // description can be renamed to say which part it is, even when the
    // original came from a bank. One still encrypted on the device stays as it
    // is: there is no readable text to edit.
    const canEditDescription =
        canEditAllFields ||
        (isSplitPartTransaction && !transaction?.description_iv);

    // Split the old way: its lines stay read-only until the conversion turns
    // them into parts, and the server refuses a category for it meanwhile.
    const legacySplitLines =
        mode === 'edit' && transaction && hasLegacySplitLines(transaction)
            ? (transaction.splits ?? [])
            : [];
    // Its lines add up to its amount, so the server refuses a new amount for it
    // as well: on a manual row the amount and the expense/income toggle are
    // shown but locked until the conversion.
    const isAmountLocked = legacySplitLines.length > 0;

    // The toggle carries the sign, so the amount input never sees a minus and
    // nobody has to type one. Everything that persists or matches on the amount
    // reads this, never the unsigned field.
    const signedAmount =
        transactionType === 'income' ? unsignedAmount : -unsignedAmount;

    // The date is the exception, and is editable on every transaction: which
    // month one counts towards is the user's call, not the bank's — a payroll
    // booked on the 27th can belong to next month's budget. The server keeps
    // the date the source gave it in source_date, so the sync watermark does
    // not advance with the move.
    //
    // Only rows the user did not type in have a source to compare against: on a
    // manual transaction the user picked every date it ever had. The comparison
    // is against the field rather than the saved row, so the hint shows the
    // moment a different date is typed, and moving it back hides it again.
    const sourceDate =
        transaction && transaction.source !== 'manually_created'
            ? (transaction.source_date ??
              transaction.transaction_date.split('T')[0])
            : null;
    const movedFromSourceDate =
        sourceDate && sourceDate !== transactionDate
            ? formatSourceDate(sourceDate, locale)
            : null;

    useEffect(() => {
        function fillFrom(source: DecryptedTransaction) {
            setDescription(source.decryptedDescription);
            setUnsignedAmount(Math.abs(source.amount));
            setTransactionType(source.amount > 0 ? 'income' : 'expense');
            setCurrencyCode(source.currency_code);
            setCategoryId(source.category_id || 'null');
            setSelectedLabelIds(
                source.label_ids || source.labels?.map((l) => l.id) || [],
            );
            setNotes(source.decryptedNotes || '');
            setShowNotes(!!source.decryptedNotes);
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
            // The chip opens filled in: the duplicated transaction's account
            // wins, then the account being read, then the one the last manual
            // transaction went to, then simply the first. Pre-filling is fine
            // because the chip shows what it picked — it is hiding it that
            // would not be. An account page that cannot take a transaction
            // (an archived account) is the exception: the field stays empty,
            // so saving asks for an account instead of landing on another one.
            const initialAccount =
                availableAccounts.find(
                    (account) => account.id === duplicateFrom?.account_id,
                ) ??
                (initialAccountId
                    ? availableAccounts.find(
                          (account) => account.id === initialAccountId,
                      )
                    : (availableAccounts.find(
                          (account) =>
                              account.id ===
                              readStoredValue(STORAGE_KEY_LAST_ACCOUNT),
                      ) ?? availableAccounts[0]));
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

    async function checkAndApplyAutomationRules() {
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
                notesIv: null,
                ruleName: null,
            };
        }

        // A key only exists for the accounts still on the legacy encryption, and
        // the engine takes a null one: it is needed to read an encrypted account
        // name, not to match a rule. Bailing out without one switched automation
        // rules off entirely for every manually created transaction.
        const keyString = getStoredKey();
        const key = keyString ? await importKey(keyString) : null;

        const result = await evaluateRulesForNewTransaction(
            {
                description: description.trim(),
                amount: signedAmount / 100,
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

        if (result.note && result.noteIv && key) {
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
            setCurrencyCode(nextCurrencyCode);
        }
    }

    /**
     * Whether the account the transaction leaves or the one it lands on has a
     * balance this edit may move. An account missing from the list is left
     * for the server to decide.
     */
    function editTouchesOwnedBalance(originalAccountId: string): boolean {
        return [originalAccountId, accountId].some((id) => {
            const account = accounts.find((acc) => acc.id === id);

            return account === undefined || ownsBalanceUpdates(account);
        });
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
        created: DecryptedTransaction,
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

    /**
     * Reveals the notes if they are hidden and puts the caret at the end of
     * what is already there. The textarea has to be in the DOM before it can
     * take focus, hence the synchronous render.
     */
    function openNotes() {
        flushSync(() => setShowNotes(true));

        const textarea = notesRef.current;

        if (textarea) {
            textarea.focus();
            textarea.setSelectionRange(
                textarea.value.length,
                textarea.value.length,
            );
        }
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        await save(false);
    }

    /**
     * `addAnother` keeps the dialog open for the next entry: the account, date,
     * type and category carry over, the amount and the prose do not.
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
                const ruleResult = await checkAndApplyAutomationRules();

                let finalCategoryId = categoryId !== 'null' ? categoryId : null;
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

                // A connected account's balance is the bank's to report and a
                // credit card has none, so the undo below must not try to
                // reverse one either.
                const balanceWasUpdated =
                    ownsBalanceUpdates(selectedAccount) && updateAccountBalance;

                const createdTransaction = await transactionSyncService.create(
                    {
                        user_id: '00000000-0000-0000-0000-000000000000',
                        account_id: accountId,
                        category_id: finalCategoryId,
                        description: finalDescription,
                        description_iv: finalDescriptionIv,
                        transaction_date: transactionDate,
                        amount: signedAmount,
                        currency_code: currencyCode,
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
                    { updateBalance: balanceWasUpdated },
                );

                const updatedCategory = createdTransaction.category_id
                    ? categories.find(
                          (category) =>
                              category.id === createdTransaction.category_id,
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

                // The rule only wrote the category when the user had left it
                // alone, which is the case the collapsed form hides.
                const ruleAppliedCategory =
                    !!ruleResult.categoryId && categoryId === 'null';

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

                let encryptedNotes: string | null = null;
                let notesIv: string | null = null;

                encryptedNotes = trimmedNotes || null;
                notesIv = null;

                const updateData: {
                    category_id?: string | null;
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
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                    // A split the old way has no category of its own to send.
                    ...(legacySplitLines.length === 0 &&
                    selectedCategoryId !== transaction.category_id
                        ? { category_id: selectedCategoryId }
                        : {}),
                };

                let finalDecryptedDescription =
                    transaction.decryptedDescription;

                if (
                    canEditDate &&
                    transactionDate !==
                        transaction.transaction_date.split('T')[0]
                ) {
                    updateData.transaction_date = transactionDate;
                }

                if (
                    canEditDescription &&
                    trimmedDescription !== transaction.decryptedDescription
                ) {
                    updateData.description = trimmedDescription;
                    updateData.description_iv = null;
                    finalDecryptedDescription = trimmedDescription;
                }

                if (canEditAllFields) {
                    if (
                        !isAmountLocked &&
                        signedAmount !== transaction.amount
                    ) {
                        updateData.amount = signedAmount;
                    }
                    if (accountId !== transaction.account_id) {
                        updateData.account_id = accountId;
                    }
                    // Its own field now, so it can change without the account:
                    // a trip paid in dollars off a euro account.
                    if (currencyCode !== transaction.currency_code) {
                        updateData.currency_code = currencyCode;
                    }
                }

                const result = await transactionSyncService.update(
                    transaction.id,
                    updateData,
                    {
                        // Gate on the transaction being manual and on either
                        // side owning its balance, not on the target alone: the
                        // backend adjuster skips the other side per-account, so
                        // this still reverses the old manual account when the
                        // edit moves it onto a connected one or a credit card.
                        updateBalance:
                            canEditAllFields &&
                            updateAccountBalance &&
                            editTouchesOwnedBalance(transaction.account_id),
                    },
                );

                const { learned_rule: learnedRule, ...serverTransaction } =
                    result;
                const savedSplits = serverTransaction.splits ?? [];
                const authoritativeCategoryId =
                    serverTransaction.category_id ?? null;
                const updatedCategory =
                    savedSplits.length === 0 && authoritativeCategoryId
                        ? categories.find(
                              (category) =>
                                  category.id === authoritativeCategoryId,
                          ) || null
                        : null;
                const authoritativeLabelIds = serverTransaction.label_ids ?? [];
                const selectedLabels = labels.filter((label) =>
                    authoritativeLabelIds.includes(label.id),
                );
                const authoritativeAccount = accounts.find(
                    (candidate) =>
                        candidate.id === serverTransaction.account_id,
                );

                const updatedTransaction: DecryptedTransaction = {
                    ...transaction,
                    ...serverTransaction,
                    category: updatedCategory,
                    label_ids: authoritativeLabelIds,
                    splits: savedSplits,
                    is_split: savedSplits.length > 0,
                    split_count: savedSplits.length,
                    decryptedDescription: finalDecryptedDescription,
                    decryptedNotes: trimmedNotes || null,
                    labels: selectedLabels,
                    account: authoritativeAccount ?? transaction.account,
                    bank: authoritativeAccount?.bank?.id
                        ? banks.find(
                              (bank) =>
                                  bank.id === authoritativeAccount.bank?.id,
                          )
                        : transaction.bank,
                };

                toast.success(__('Transaction updated successfully'));
                onSuccess(updatedTransaction);

                if (learnedRule) {
                    // The correction already taught the system a forward rule, so
                    // confirm that and offer an instant undo — and skip the
                    // "Automatize" prompt, which would only offer to create a rule
                    // that now exists. Mirrors the transaction-table flow.
                    const ruleId = learnedRule.id;

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
            const validationMessage = axios.isAxiosError(error)
                ? Object.values(error.response?.data?.errors ?? {})
                      .flat()
                      .find((message): message is string =>
                          Boolean(message && typeof message === 'string'),
                      )
                : null;
            toast.error(
                validationMessage ??
                    (mode === 'create'
                        ? __('Failed to create transaction')
                        : __('Failed to update transaction')),
            );
        } finally {
            setIsSubmitting(false);
        }
    }

    const selectedAccount = accounts.find((acc) => acc.id === accountId);
    // A connected account's balance is the bank's and a credit card has none,
    // so neither offers to update one.
    const offersBalanceUpdate =
        !selectedAccount || ownsBalanceUpdates(selectedAccount);
    // A new transaction opens on the amount, the description and the chips;
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
            onValueChange={setCurrencyCode}
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

    const accountName = transaction
        ? decryptedAccountNames.get(transaction.account_id)
        : undefined;

    const headerCategory =
        categoryId !== 'null'
            ? (categories.find((category) => category.id === categoryId) ??
              null)
            : null;

    const formattedAmount = transaction
        ? new Intl.NumberFormat(locale, {
              style: 'currency',
              currency: transaction.currency_code,
          })
              .format(transaction.amount / 100)
              .replace(/\s/g, '\u202F')
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

    // What the source reported and the user cannot change. The date is only
    // here on a part of a split: this fork lets every other transaction be
    // moved, so it stays an editable field below rather than a locked row.
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
                        value: formatSourceDate(
                            transaction.transaction_date.split('T')[0],
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
          ].filter(
              (row): row is { label: string; value: string } => row !== null,
          )
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

    // In context, where the amount is: splitting replaces this transaction
    // with its parts, so it closes this dialog for the split one.
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
            <Textarea
                id="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder={__('Transaction description')}
                disabled={isSubmitting}
                required
                rows={3}
            />
        </div>
    );

    const dateField = (
        <div className="space-y-2">
            <FormLabel htmlFor="date">{__('Date')}</FormLabel>
            {/* Without a max, Chromium lets the year take six digits, and the
                server reads "20266-10-01" as 2006-10-01. */}
            <Input
                id="date"
                type="date"
                max="9999-12-31"
                value={transactionDate}
                onChange={(e) => setTransactionDate(e.target.value)}
                disabled={isSubmitting}
                autoFocus={mode === 'create' && showDateField}
                required
            />
            <p className="text-sm text-muted-foreground">
                {__(
                    'The date decides which month and budget this transaction counts towards.',
                )}
            </p>
            {movedFromSourceDate && (
                <p
                    data-testid="original-transaction-date"
                    className="text-sm text-muted-foreground"
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
            {`${decryptedAccountNames.get(account.id) || __('[Loading...]')} · ${account.currency_code}`}
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
            : formatSourceDate(transactionDate, locale);

    // The collapsed form's defaults. The account and category ones are the
    // select itself wearing a pill, so picking from them stays one tap. The
    // row only renders while nothing is split, so the category chip never
    // stands in for split lines.
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
                        {decryptedAccountNames.get(accountId) ||
                            __('Select account')}
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

            {/* A connected account's balance is the bank's and a credit card
                has none, so neither has anything here to switch off. */}
            {offersBalanceUpdate && (
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
        offersBalanceUpdate && (
            <div className="flex items-center gap-2">
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
                    className="cursor-pointer font-normal"
                >
                    {__('Update account balance')}
                </FormLabel>
            </div>
        )
    );

    // Edit is where we already are; every other action leaves the dialog.
    const rowMenuActions =
        mode === 'edit' && transaction && rowActions
            ? getTransactionRowActions({ transaction, ...rowActions })
                  .filter((action) => action.id !== 'edit')
                  .map((action) => ({
                      ...action,
                      onSelect: () => {
                          onOpenChange(false);
                          action.onSelect();
                      },
                  }))
            : [];

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

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-[525px]"
                tabIndex={!canEditAllFields ? -1 : undefined}
                onOpenAutoFocus={
                    !canEditAllFields
                        ? (event) => {
                              event.preventDefault();
                              (event.currentTarget as HTMLElement).focus();
                          }
                        : undefined
                }
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
                            : canEditAllFields
                              ? __('Update this transaction.')
                              : isSplitPartTransaction && canEditDescription
                                ? __(
                                      'Update the description, category and notes for this part of the split.',
                                  )
                                : __(
                                      'Update the category and notes for this transaction.',
                                  )}
                    </DialogDescription>
                </DialogHeader>

                {/* Registered in here so they land on the dialog's layer:
                    the page's shortcuts sleep while it is open. */}
                <Shortcut
                    id="transaction-dialog.save"
                    onTrigger={() => formRef.current?.requestSubmit()}
                    enabled={!isSubmitting}
                />
                <Shortcut
                    id="transaction-dialog.add-note"
                    onTrigger={openNotes}
                    enabled={!isMinimal && !isSubmitting}
                />

                <form ref={formRef} onSubmit={handleSubmit}>
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
                                            disabled={
                                                isSubmitting || isAmountLocked
                                            }
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
                                                placeholder="25.00"
                                                disabled={
                                                    isSubmitting ||
                                                    isAmountLocked
                                                }
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
                                    <div className="flex items-center gap-3">
                                        {headerCategory ? (
                                            <CategoryIcon
                                                category={headerCategory}
                                                className="p-2.5"
                                                iconClassName="size-5 sm:size-5"
                                            />
                                        ) : (
                                            <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                <HelpCircle className="size-4 text-zinc-500" />
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">
                                                {description}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {accountName ?? ''}
                                            </div>
                                        </div>
                                        <div className="shrink-0 text-2xl font-semibold tabular-nums">
                                            {formattedAmount}
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

                        {!isMinimal && (
                            <>
                                {legacySplitLines.length > 0 ? (
                                    <div
                                        className="space-y-2"
                                        data-testid="legacy-split-lines"
                                    >
                                        <FormLabel>{__('Category')}</FormLabel>
                                        <div className="rounded-md border px-3 py-2.5">
                                            <SplitBreakdown
                                                lines={buildSplitBreakdown(
                                                    legacySplitLines,
                                                )}
                                                count={legacySplitLines.length}
                                                currencyCode={
                                                    transaction?.currency_code ??
                                                    currencyCode
                                                }
                                            />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                'This split is in the old format and will be converted into parts. Until then its lines, its category and its amount cannot be changed.',
                                            )}
                                        </p>
                                    </div>
                                ) : (
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
                                )}

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

                                {showNotes ? (
                                    <div className="space-y-2">
                                        <FormLabel htmlFor="notes">
                                            {__('Notes')}
                                        </FormLabel>
                                        <Textarea
                                            id="notes"
                                            ref={notesRef}
                                            placeholder={__('Add notes...')}
                                            value={notes}
                                            onChange={(e) =>
                                                setNotes(e.target.value)
                                            }
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
                                        onClick={openNotes}
                                        disabled={isSubmitting}
                                        aria-keyshortcuts={
                                            addNoteShortcut?.ariaKeyShortcuts
                                        }
                                    >
                                        <Plus />
                                        {__('Add note')}
                                        <ShortcutKbd id="transaction-dialog.add-note" />
                                    </Button>
                                )}
                            </>
                        )}

                        {mode === 'create' && moreOptionsTrigger}
                    </div>

                    <DialogFooter>
                        {mode === 'edit' &&
                            onDelete &&
                            transaction &&
                            !isSplitPartTransaction && (
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
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
                            aria-keyshortcuts={saveShortcut?.ariaKeyShortcuts}
                        >
                            {isSubmitting
                                ? __('Saving...')
                                : mode === 'create'
                                  ? __('Create Transaction')
                                  : __('Save Changes')}
                            {!isSubmitting && (
                                <ShortcutKbd id="transaction-dialog.save" />
                            )}
                        </Button>
                    </DialogFooter>
                </form>
                {rowMenuActions.length > 0 && (
                    // Beside the dialog's X, and last in the DOM so it never
                    // takes the focus the dialog opens with. The table's row
                    // menu already offers all this from md up.
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={isSubmitting}
                                aria-label={__('More actions')}
                                data-testid="transaction-more-actions"
                                className="absolute top-2.5 right-11 size-7 opacity-70 hover:opacity-100 md:hidden"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <TransactionRowDropdownMenuContent
                            align="end"
                            actions={rowMenuActions}
                        />
                    </DropdownMenu>
                )}
            </DialogContent>
        </Dialog>
    );
}
