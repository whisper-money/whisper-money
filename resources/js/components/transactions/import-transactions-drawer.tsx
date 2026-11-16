import { store as storeBalance } from '@/actions/App/Http/Controllers/AccountBalanceController';
import { categorize } from '@/actions/App/Http/Controllers/TransactionController';
import { AiColumnDetection } from '@/components/ai-column-detection';
import AlertError from '@/components/alert-error';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Progress } from '@/components/ui/progress';
import { useAiColumnSuggestion } from '@/hooks/use-ai-column-suggestion';
import { accountHasBalance } from '@/hooks/use-owns-balance-updates';
import {
    suggestedTransactionMapping,
    TRANSACTION_FIELDS,
} from '@/lib/ai-import-mapping';
import { describeColumns } from '@/lib/column-shape';
import { importKey } from '@/lib/crypto';
import { getCsrfToken } from '@/lib/csrf';
import {
    collectBalancesToImport,
    convertRowsToTransactions,
} from '@/lib/file-parser';
import { saveImportConfig } from '@/lib/import-config-storage';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import {
    applyStoredImportConfig,
    applyTransactionSuggestion,
    parseImportFile,
} from '@/lib/transaction-import';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import {
    DateFormat,
    ImportStep,
    type ColumnMapping,
    type ImportState,
    type ParsedRow,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ImportStepAccount } from './import-step-account';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';
import { ImportStepUpload } from './import-step-upload';

/**
 * This drawer imports into one account, in that account's currency, so it
 * never reads a currency column. The shared parser still spots one (the full
 * import uses it); left in, it would be saved with the account's layout and
 * make the next file without that column skip the saved layout.
 */
function withoutCurrencyColumn(mapping: ColumnMapping): ColumnMapping {
    return { ...mapping, currency: null };
}

interface ImportTransactionsDrawerProps {
    accounts?: Account[];
    categories?: Category[];
    banks?: Bank[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Receives how many transactions actually made it in (0 when all failed). */
    onImportComplete?: (importedCount: number) => void;
    autoSelectSingleAccount?: boolean;
}

interface ImportError {
    rowNumber: number;
    transaction: {
        date: string;
        description: string;
        amount: string;
    };
    error: string;
}

export function ImportTransactionsDrawer({
    accounts = [],
    categories = [],
    banks = [],
    automationRules = [],
    open,
    onOpenChange,
    onImportComplete,
    autoSelectSingleAccount = false,
}: ImportTransactionsDrawerProps) {
    const { locale } = usePage<SharedData>().props;
    const [isImporting, setIsImporting] = useState(false);
    const [importProgress, setImportProgress] = useState(0);
    const [importTotal, setImportTotal] = useState(0);
    const [importErrors, setImportErrors] = useState<ImportError[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [wasSingleAccountAutoSelected, setWasSingleAccountAutoSelected] =
        useState(false);
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(
        null,
    );
    const ai = useAiColumnSuggestion();
    const { begin: resetAi } = ai;
    // A credit card has no balance, so the import neither asks for one nor
    // carries one over from the file.
    const tracksBalance = selectedAccount
        ? accountHasBalance(selectedAccount)
        : true;
    const [state, setState] = useState<ImportState>({
        step: ImportStep.SelectAccount,
        selectedAccountId: null,
        file: null,
        parsedData: [],
        columnHeaders: [],
        hasHeaderRow: false,
        columnOptions: [],
        columnMapping: {
            transaction_date: null,
            description: null,
            amount: null,
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: null,
            expense_types: [],
        },
        dateFormat: DateFormat.YearMonthDay,
        dateFormatDetected: false,
        transactions: [],
    });

    useEffect(() => {
        if (state.selectedAccountId) {
            const account = accounts.find(
                (a) => a.id === state.selectedAccountId,
            );
            if (account) {
                setSelectedAccount(account);
            }
        }
    }, [state.selectedAccountId, accounts]);

    useEffect(() => {
        if (!open) {
            setState({
                step: ImportStep.SelectAccount,
                selectedAccountId: null,
                file: null,
                parsedData: [],
                columnHeaders: [],
                hasHeaderRow: false,
                columnOptions: [],
                columnMapping: {
                    transaction_date: null,
                    description: null,
                    amount: null,
                    currency: null,
                    balance: null,
                    creditor_name: null,
                    debtor_name: null,
                    transaction_type: null,
                    expense_types: [],
                },
                dateFormat: DateFormat.YearMonthDay,
                dateFormatDetected: false,
                transactions: [],
            });
            setIsImporting(false);
            setError(null);
            setWasSingleAccountAutoSelected(false);
            setSelectedAccount(null);
            resetAi();
        }
    }, [open, resetAi]);

    const handleAccountSelect = (accountId: UUID) => {
        setState((prev) => ({ ...prev, selectedAccountId: accountId }));
    };

    const handleSingleAccountAutoSelect = (accountId: UUID) => {
        setWasSingleAccountAutoSelected(true);
        handleAccountSelect(accountId);
    };

    /**
     * Ask the AI which column is which for the file on screen. Its answer
     * lands after the mapping is shown, so it is dropped if another file was
     * picked meanwhile, and leaves alone the fields picked by hand — unless
     * the user pressed the button, which asks for every field.
     * `description` is the mapping's own, kept when the AI places none.
     */
    const requestSuggestion = (
        file: File,
        headers: string[],
        rows: ParsedRow[],
        hasHeaderRow: boolean,
        description: ColumnMapping['description'],
        everything: boolean,
        token?: number,
    ) => {
        const columns = describeColumns(headers, rows, hasHeaderRow);

        void ai.request<ColumnMapping>({
            kind: 'transaction',
            columns,
            headers,
            accountId: state.selectedAccountId,
            everything,
            token,
            // This drawer never reads a currency column (see
            // withoutCurrencyColumn), so the suggestion leaves it out too.
            toMapping: (suggestions) => ({
                ...suggestedTransactionMapping(
                    suggestions,
                    columns,
                    description,
                ),
                currency: null,
            }),
            apply: (suggested, keep) =>
                setState((prev) => {
                    if (prev.file !== file) {
                        return prev;
                    }

                    const next = applyTransactionSuggestion(
                        {
                            mapping: prev.columnMapping,
                            dateFormat: prev.dateFormat,
                            dateFormatDetected: prev.dateFormatDetected,
                        },
                        prev.parsedData,
                        suggested,
                        keep,
                        locale,
                    );

                    return {
                        ...prev,
                        columnMapping: next.mapping,
                        dateFormat: next.dateFormat,
                        dateFormatDetected: next.dateFormatDetected,
                    };
                }),
        });
    };

    const handleFileSelect = async (file: File) => {
        const token = ai.begin();

        if (!file) {
            setState((prev) => ({
                ...prev,
                file: null,
                parsedData: [],
                columnHeaders: [],
                columnOptions: [],
            }));
            return;
        }

        try {
            const parsed = await parseImportFile(file, locale);
            const {
                headers,
                rows: data,
                hasHeaderRow,
                columnOptions,
                mapping: autoMapping,
                dateFormat: detectedFormat,
                dateFormatDetected: formatDetected,
            } = parsed;

            // Show the parsed file immediately with auto-detected columns; the
            // saved per-account config is fetched off the critical path so a
            // slow or hanging network never blocks the preview or Next button.
            setState((prev) => ({
                ...prev,
                file,
                parsedData: data,
                columnHeaders: headers,
                hasHeaderRow,
                columnOptions,
                columnMapping: withoutCurrencyColumn(autoMapping),
                dateFormat: detectedFormat,
                dateFormatDetected: formatDetected,
            }));

            const accountId = state.selectedAccountId;
            const stored = accountId
                ? await applyStoredImportConfig(parsed, accountId)
                : null;

            ai.afterStoredLayout(token, stored, TRANSACTION_FIELDS, () => {
                // With no row below the one taken for headers there is no
                // column to describe, so the AI is not asked.
                if (parsed.rows.length > 0) {
                    requestSuggestion(
                        file,
                        parsed.headers,
                        parsed.rows,
                        parsed.hasHeaderRow,
                        parsed.mapping.description,
                        false,
                        token,
                    );
                }
            });

            if (!stored) {
                return;
            }

            // Only apply if this file is still the selected one, so a slow
            // load can't clobber a file picked afterwards. Keep the saved
            // format as the default, but still show the selector when the
            // dates are ambiguous so a previously-saved wrong format can be
            // corrected.
            setState((prev) =>
                prev.file === file
                    ? {
                          ...prev,
                          columnMapping: withoutCurrencyColumn(stored.mapping),
                          dateFormat: stored.dateFormat,
                          dateFormatDetected: stored.dateFormatDetected,
                      }
                    : prev,
            );
        } catch (err) {
            setError(
                err instanceof Error ? err.message : 'Failed to parse file',
            );
        }
    };

    const handleMappingChange = (
        field: keyof ColumnMapping,
        value: string | string[],
    ) => {
        ai.touch(field);
        setState((prev) => ({
            ...prev,
            columnMapping: {
                ...prev.columnMapping,
                [field]: value,
            },
        }));
    };

    const handleDateFormatChange = (format: DateFormat) => {
        setState((prev) => ({ ...prev, dateFormat: format }));
    };

    const handlePreviewTransactions = async () => {
        try {
            const account = accounts.find(
                (a) => a.id === state.selectedAccountId,
            );

            if (!account) {
                setError('Selected account not found');
                return;
            }

            // A credit card has no balance at all, so none of its rows carries
            // one, even when a saved mapping still names a balance column.
            const carriesBalance = accountHasBalance(account);

            const parsedTransactions = convertRowsToTransactions(
                state.parsedData,
                state.columnMapping,
                state.dateFormat,
            ).map((transaction) =>
                carriesBalance
                    ? transaction
                    : { ...transaction, balance: null },
            );

            const duplicateFlags = await transactionSyncService.checkDuplicates(
                account.id,
                parsedTransactions,
            );

            const transactionsWithDuplicateCheck = parsedTransactions.map(
                (transaction, index) => ({
                    ...transaction,
                    isDuplicate: duplicateFlags[index],
                    selected: !duplicateFlags[index],
                }),
            );

            setState((prev) => ({
                ...prev,
                transactions: transactionsWithDuplicateCheck,
                step: ImportStep.Preview,
            }));
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to process transactions',
            );
        }
    };

    const handleConfirmImport = async () => {
        setIsImporting(true);
        setError(null);
        setImportErrors([]);

        // The row's index in the full list, carried along so the rows that make
        // it in can be marked and left out of a retry.
        const selectedIndexes = state.transactions
            .map((transaction, index) => (transaction.selected ? index : -1))
            .filter((index) => index !== -1);
        const newTransactions = selectedIndexes.map(
            (index) => state.transactions[index],
        );
        const total = newTransactions.length;
        setImportTotal(total);
        setImportProgress(0);

        if (!selectedAccount) {
            setError('Selected account not found');
            setIsImporting(false);
            return;
        }

        const createdTransactions: unknown[] = [];
        const importedIndexes = new Set<number>();
        const errors: ImportError[] = [];
        const keyString = getStoredKey();
        const key = keyString ? await importKey(keyString) : null;
        const rules = key ? automationRules : [];

        const BATCH_SIZE = 20;
        let processedCount = 0;
        let uncategorizedCount = 0;

        for (let i = 0; i < newTransactions.length; i += BATCH_SIZE) {
            const batch = newTransactions.slice(i, i + BATCH_SIZE);

            const batchResults = await Promise.allSettled(
                batch.map(async (transaction, batchIndex) => {
                    const rowNumber = i + batchIndex + 1;

                    const encrypted: string = transaction.description;
                    const iv: string | null = null;

                    let categoryId: string | null = null;
                    let notes: string | null = null;
                    let notesIv: string | null = null;
                    let labelIds: string[] = [];

                    if (key && rules.length > 0) {
                        const ruleMatch = await evaluateRulesForNewTransaction(
                            {
                                description: transaction.description,
                                amount: transaction.amount / 100,
                                transaction_date: transaction.transaction_date,
                                account_id: selectedAccount.id,
                                creditor_name: transaction.creditor_name,
                                debtor_name: transaction.debtor_name,
                            },
                            rules,
                            categories,
                            accounts,
                            banks,
                            key,
                        );

                        if (ruleMatch) {
                            if (ruleMatch.categoryId) {
                                categoryId = ruleMatch.categoryId;
                            }
                            if (ruleMatch.note && ruleMatch.noteIv) {
                                const { decrypt } =
                                    await import('@/lib/crypto');
                                notes = await decrypt(
                                    ruleMatch.note,
                                    key,
                                    ruleMatch.noteIv,
                                );
                                notesIv = null;
                            }
                            if (
                                ruleMatch.labelIds &&
                                ruleMatch.labelIds.length > 0
                            ) {
                                labelIds = ruleMatch.labelIds;
                            }
                        }
                    }

                    const transactionData = {
                        user_id:
                            (selectedAccount as Account & { user_id?: string })
                                .user_id ||
                            '00000000-0000-0000-0000-000000000000',
                        account_id: selectedAccount.id,
                        category_id: categoryId,
                        description: encrypted,
                        description_iv: iv,
                        transaction_date: transaction.transaction_date,
                        amount: transaction.amount,
                        currency_code: selectedAccount.currency_code,
                        notes: notes,
                        notes_iv: notesIv,
                        creditor_name: transaction.creditor_name ?? null,
                        debtor_name: transaction.debtor_name ?? null,
                        source: 'imported' as const,
                        label_ids: labelIds.length > 0 ? labelIds : undefined,
                    };

                    const createdTransaction =
                        await transactionSyncService.create(transactionData);

                    return {
                        success: true,
                        transaction: createdTransaction,
                        rowNumber,
                        hasCategory: categoryId !== null,
                    };
                }),
            );

            batchResults.forEach((result, batchIndex) => {
                const transaction = batch[batchIndex];
                const rowNumber = i + batchIndex + 1;

                if (result.status === 'fulfilled') {
                    createdTransactions.push(result.value.transaction);
                    importedIndexes.add(selectedIndexes[i + batchIndex]);
                    if (!result.value.hasCategory) {
                        uncategorizedCount++;
                    }
                } else {
                    const errorMessage =
                        result.reason instanceof Error
                            ? result.reason.message
                            : __('Unknown error');

                    // Console output lands in Sentry breadcrumbs: the row and
                    // the raw error (an axios error carries the request body)
                    // hold the user's description and amount, so neither goes.
                    console.error(
                        `Transaction ${rowNumber} failed:`,
                        errorMessage,
                    );

                    errors.push({
                        rowNumber,
                        transaction: {
                            date: transaction.transaction_date,
                            description: transaction.description,
                            amount: transaction.amount.toString(),
                        },
                        error: errorMessage,
                    });
                }
            });

            processedCount += batch.length;
            setImportProgress(processedCount);
        }

        // The server refuses a credit card balance; none is sent for one.
        const balancesToImport = accountHasBalance(selectedAccount)
            ? collectBalancesToImport(newTransactions)
            : new Map<string, number>();

        if (balancesToImport.size > 0) {
            try {
                const xsrfToken = getCsrfToken();

                const balanceRecords = Array.from(balancesToImport.entries());

                for (const [date, balance] of balanceRecords) {
                    await fetch(storeBalance.url(selectedAccount.id), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': xsrfToken,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            balance_date: date,
                            balance,
                        }),
                    });
                }
            } catch (err) {
                console.error('Failed to import balances:', err);
            }
        }

        // A partial import keeps the drawer open on the same preview, so the
        // rows already created have to be taken out of the selection: pressing
        // Import again would otherwise create every one of them a second time.
        if (importedIndexes.size > 0) {
            setState((prev) => ({
                ...prev,
                transactions: prev.transactions.map((transaction, index) =>
                    importedIndexes.has(index)
                        ? { ...transaction, imported: true, selected: false }
                        : transaction,
                ),
            }));
        }

        setImportErrors(errors);
        setIsImporting(false);

        const successCount = createdTransactions.length;

        // Only a mapping that actually brought rows in is worth remembering:
        // an abandoned or failed import would leave a bad layout behind.
        if (successCount > 0) {
            void saveImportConfig(selectedAccount.id, {
                columnMapping: state.columnMapping,
                dateFormat: state.dateFormat,
                aiSuggested: ai.importedWith(
                    state.columnMapping,
                    'account_drawer',
                    'transaction',
                ),
            });
        }

        const errorCount = errors.length;

        console.log('Import complete:', { successCount, errorCount, total });

        if (errorCount === 0 && successCount > 0) {
            const message =
                uncategorizedCount > 0
                    ? `${successCount} transaction${successCount !== 1 ? 's' : ''} imported (${uncategorizedCount} uncategorized)`
                    : `${successCount} transaction${successCount !== 1 ? 's' : ''} imported successfully`;
            toast.success(message, {
                action:
                    uncategorizedCount > 0
                        ? {
                              label: 'Categorize',
                              onClick: () => router.visit(categorize.url()),
                          }
                        : undefined,
            });
            onOpenChange(false);
        } else if (successCount > 0 && errorCount > 0) {
            const message =
                uncategorizedCount > 0
                    ? `${successCount} transaction${successCount !== 1 ? 's' : ''} imported (${uncategorizedCount} uncategorized), ${errorCount} failed`
                    : `${successCount} transaction${successCount !== 1 ? 's' : ''} imported, ${errorCount} failed`;
            toast.warning(message, {
                action:
                    uncategorizedCount > 0
                        ? {
                              label: 'Categorize',
                              onClick: () => router.visit(categorize.url()),
                          }
                        : undefined,
            });
        } else {
            toast.error(__('All transactions failed to import'));
        }

        onImportComplete?.(successCount);
    };

    const handleSelectionChange = (index: number, selected: boolean) => {
        setState((prev) => ({
            ...prev,
            transactions: prev.transactions.map((t, i) =>
                i === index ? { ...t, selected } : t,
            ),
        }));
    };

    const handleSelectAll = (selected: boolean) => {
        setState((prev) => ({
            ...prev,
            transactions: prev.transactions.map((t) =>
                t.isDuplicate || t.imported ? t : { ...t, selected },
            ),
        }));
    };

    const moveToStep = (step: ImportStep) => {
        setState((prev) => ({ ...prev, step }));
    };

    const getStepInfo = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return {
                    title: __('Select Account'),
                    description: __(
                        'Choose the account where transactions will be imported',
                    ),
                };
            case ImportStep.UploadFile:
                return {
                    title: __('Upload File'),
                    description: __(
                        'Drop your CSV or Excel file here, or click to browse',
                    ),
                };
            case ImportStep.MapColumns:
                return {
                    title: __('Map Columns'),
                    description: __(
                        'Match your file columns to transaction fields',
                    ),
                };
            case ImportStep.Preview:
                return {
                    title: __('Preview Transactions'),
                    description: __('Review transactions before importing'),
                };
            default:
                if (isImporting) {
                    return {
                        title: __('Importing Transactions'),
                        description: __(
                            'Please wait while we import your transactions',
                        ),
                    };
                }

                return {
                    title: __('Import Transactions'),
                    description: __(
                        'Import transactions from CSV or Excel files',
                    ),
                };
        }
    };

    const renderStep = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return (
                    <ImportStepAccount
                        accounts={accounts}
                        selectedAccountId={state.selectedAccountId}
                        onAccountSelect={handleAccountSelect}
                        onNext={() => {
                            moveToStep(ImportStep.UploadFile);
                        }}
                        autoSelectSingleAccount={autoSelectSingleAccount}
                        onAutoSelect={handleSingleAccountAutoSelect}
                    />
                );

            case ImportStep.UploadFile:
                return (
                    <ImportStepUpload
                        file={state.file}
                        onFileSelect={handleFileSelect}
                        onNext={() => {
                            moveToStep(ImportStep.MapColumns);
                        }}
                        onBack={() => moveToStep(ImportStep.SelectAccount)}
                        showBackButton={!wasSingleAccountAutoSelected}
                    />
                );

            case ImportStep.MapColumns:
                return (
                    <ImportStepMapping
                        columnOptions={state.columnOptions}
                        columnMapping={
                            tracksBalance
                                ? state.columnMapping
                                : { ...state.columnMapping, balance: null }
                        }
                        dateFormat={state.dateFormat}
                        dateFormatDetected={state.dateFormatDetected}
                        parsedData={state.parsedData}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        tracksBalance={tracksBalance}
                        onMappingChange={handleMappingChange}
                        onDateFormatChange={handleDateFormatChange}
                        onNext={handlePreviewTransactions}
                        onBack={() => moveToStep(ImportStep.UploadFile)}
                        aiDetection={
                            <AiColumnDetection
                                status={ai.status(state.columnMapping)}
                                onDetect={() =>
                                    state.file &&
                                    requestSuggestion(
                                        state.file,
                                        state.columnHeaders,
                                        state.parsedData,
                                        state.hasHeaderRow,
                                        state.columnMapping.description,
                                        true,
                                    )
                                }
                            />
                        }
                    />
                );

            case ImportStep.Preview:
                return (
                    <ImportStepPreview
                        transactions={state.transactions}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        accountId={selectedAccount?.id || ''}
                        onConfirm={handleConfirmImport}
                        onBack={() => moveToStep(ImportStep.MapColumns)}
                        onSelectionChange={handleSelectionChange}
                        onSelectAll={handleSelectAll}
                        isImporting={isImporting}
                    />
                );

            default:
                return null;
        }
    };

    const renderImportProgress = () => {
        const percentage =
            importTotal > 0 ? (importProgress / importTotal) * 100 : 0;

        return (
            <div className="flex flex-col gap-6">
                <div className="space-y-4">
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            {importProgress} of {importTotal}{' '}
                            {__('transactions imported')}
                        </span>
                        <span>{Math.round(percentage)}%</span>
                    </div>
                    <Progress value={percentage} className="h-4" />
                </div>

                {importErrors.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-medium text-destructive">
                                {__('Errors (')}
                                {importErrors.length})
                            </h3>
                        </div>
                        <div className="max-h-[300px] overflow-y-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-muted">
                                    <tr className="border-b">
                                        <th className="px-4 py-2 text-left font-medium">
                                            {__('Row')}
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            {__('Date')}
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            {__('Description')}
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            {__('Amount')}
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            {__('Error')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {importErrors.map((error, index) => (
                                        <tr key={index} className="border-b">
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {error.rowNumber}
                                            </td>
                                            <td className="px-4 py-2">
                                                {error.transaction.date}
                                            </td>
                                            <td className="max-w-[200px] truncate px-4 py-2">
                                                {error.transaction.description}
                                            </td>
                                            <td className="px-4 py-2 font-mono">
                                                {error.transaction.amount}
                                            </td>
                                            <td className="max-w-[200px] truncate px-4 py-2 text-destructive">
                                                {error.error}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        );
    };

    const stepInfo = getStepInfo();

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="ph-no-capture h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto w-full max-w-5xl overflow-y-auto p-6">
                    <DrawerHeader className="px-0">
                        <DrawerTitle>{stepInfo.title}</DrawerTitle>
                        <DrawerDescription>
                            {stepInfo.description}
                        </DrawerDescription>
                    </DrawerHeader>
                    {error && (
                        <div className="mt-4">
                            <AlertError errors={[error]} />
                        </div>
                    )}
                    <div className="mt-4">
                        {isImporting ? renderImportProgress() : renderStep()}
                    </div>
                </div>
            </DrawerContent>
        </Drawer>
    );
}
