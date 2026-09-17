import { index as indexBalances } from '@/actions/App/Http/Controllers/AccountBalanceController';
import { categorize } from '@/actions/App/Http/Controllers/TransactionController';
import AlertError from '@/components/alert-error';
import { ImportStepUpload } from '@/components/import-step-upload';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Progress } from '@/components/ui/progress';
import {
    calculateBalancesFromTransactions,
    convertRowsToTransactions,
    isInAccountCurrency,
} from '@/lib/file-parser';
import { saveImportConfig } from '@/lib/import-config-storage';
import {
    applyStoredImportConfig,
    importTransactions,
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
    type ParsedTransaction,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ImportStepAccount } from './import-step-account';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';

interface ImportTransactionsDrawerProps {
    accounts?: Account[];
    categories?: Category[];
    banks?: Bank[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Receives how many transactions actually made it in (0 when all failed). */
    onImportComplete?: (importedCount: number) => void;
}

const EMPTY_STATE: ImportState = {
    step: ImportStep.SelectAccount,
    selectedAccountId: null,
    file: null,
    parsedData: [],
    rowNumbers: [],
    columnHeaders: [],
    columnOptions: [],
    columnMapping: {
        transaction_date: null,
        description: null,
        amount: null,
        currency: null,
        balance: null,
        creditor_name: null,
        debtor_name: null,
    },
    dateFormat: DateFormat.YearMonthDay,
    dateFormatDetected: false,
    transactions: [],
    calculateBalances: false,
    referenceBalance: null,
    referenceBalanceDate: null,
    referenceBalancePrefilled: false,
};

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
}: ImportTransactionsDrawerProps) {
    const { locale, features, currencies } = usePage<SharedData>().props;
    const supportedCurrencies = useMemo(
        () => currencies.accounts.map((currency) => currency.code),
        [currencies],
    );
    const [isImporting, setIsImporting] = useState(false);
    const [importProgress, setImportProgress] = useState(0);
    const [importTotal, setImportTotal] = useState(0);
    const [importErrors, setImportErrors] = useState<ImportError[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(
        null,
    );
    const [state, setState] = useState<ImportState>(EMPTY_STATE);

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
            setState(EMPTY_STATE);
            setIsImporting(false);
            setError(null);
            setSelectedAccount(null);
        }
    }, [open]);

    const handleAccountSelect = (accountId: UUID) => {
        setState((prev) => ({ ...prev, selectedAccountId: accountId }));
    };

    const handleFileSelect = async (file: File) => {
        // Whatever the last file failed on no longer applies to this one.
        setError(null);

        if (!file) {
            setState((prev) => ({
                ...prev,
                file: null,
                parsedData: [],
                rowNumbers: [],
                columnHeaders: [],
                columnOptions: [],
            }));
            return;
        }

        try {
            const parsed = await parseImportFile(file, locale);

            // Show the parsed file immediately with auto-detected columns; the
            // saved per-account config is fetched off the critical path so a
            // slow or hanging network never blocks the preview or Next button.
            setState((prev) => ({
                ...prev,
                file,
                parsedData: parsed.rows,
                rowNumbers: parsed.rowNumbers,
                columnHeaders: parsed.headers,
                columnOptions: parsed.columnOptions,
                columnMapping: parsed.mapping,
                dateFormat: parsed.dateFormat,
                dateFormatDetected: parsed.dateFormatDetected,
            }));

            const accountId = state.selectedAccountId;

            if (!accountId) {
                return;
            }

            const stored = await applyStoredImportConfig(parsed, accountId);

            if (!stored) {
                return;
            }

            // Only apply if this file is still the selected one, so a slow load
            // can't clobber a file picked afterwards.
            setState((prev) =>
                prev.file === file
                    ? {
                          ...prev,
                          columnMapping: stored.mapping,
                          dateFormat: stored.dateFormat,
                          dateFormatDetected: stored.dateFormatDetected,
                      }
                    : prev,
            );
        } catch (err) {
            setError(
                __(err instanceof Error ? err.message : 'Failed to parse file'),
            );
        }
    };

    const handleMappingChange = (
        field: keyof ColumnMapping,
        value: string | string[],
    ) => {
        setState((prev) => {
            const next = {
                ...prev,
                columnMapping: {
                    ...prev.columnMapping,
                    [field]: value,
                },
            };
            // Setting a balance column disables the calculate-balances option
            if (field === 'balance' && value) {
                next.calculateBalances = false;
                next.referenceBalance = null;
                next.referenceBalanceDate = null;
                next.referenceBalancePrefilled = false;
            }
            return next;
        });
    };

    const handleCalculateBalancesChange = (enabled: boolean) => {
        setState((prev) => ({
            ...prev,
            calculateBalances: enabled,
            referenceBalance: enabled ? prev.referenceBalance : null,
            referenceBalancePrefilled: enabled
                ? prev.referenceBalancePrefilled
                : false,
        }));
    };

    const handleReferenceBalanceChange = (balanceInCents: number) => {
        setState((prev) => ({
            ...prev,
            referenceBalance: balanceInCents,
            referenceBalancePrefilled: false,
        }));
    };

    const handleLatestDateChange = useCallback((date: string | null) => {
        setState((prev) => {
            if (prev.referenceBalanceDate === date) {
                return prev;
            }
            return {
                ...prev,
                referenceBalanceDate: date,
                referenceBalance: null,
                referenceBalancePrefilled: false,
            };
        });
    }, []);

    // Try to pre-fill the reference balance from an existing balance record
    // on that date. If found, no need to ask the user.
    useEffect(() => {
        if (
            !state.calculateBalances ||
            !state.referenceBalanceDate ||
            !state.selectedAccountId ||
            state.referenceBalance !== null
        ) {
            return;
        }

        let cancelled = false;

        (async () => {
            try {
                const response = await fetch(
                    indexBalances.url(state.selectedAccountId as string, {
                        query: { page: '1' },
                    }),
                    { headers: { Accept: 'application/json' } },
                );
                if (!response.ok) {
                    return;
                }
                const json = (await response.json()) as {
                    data: { balance_date: string; balance: number }[];
                };
                if (cancelled) {
                    return;
                }
                const match = json.data.find(
                    (b) => b.balance_date === state.referenceBalanceDate,
                );
                if (match) {
                    setState((prev) => {
                        if (
                            prev.referenceBalanceDate !==
                                state.referenceBalanceDate ||
                            prev.referenceBalance !== null
                        ) {
                            return prev;
                        }
                        return {
                            ...prev,
                            referenceBalance: match.balance,
                            referenceBalancePrefilled: true,
                        };
                    });
                }
            } catch (err) {
                console.error('Failed to load reference balance:', err);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [
        state.calculateBalances,
        state.referenceBalanceDate,
        state.selectedAccountId,
        state.referenceBalance,
    ]);

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

            const ownMoney = (transaction: ParsedTransaction): boolean =>
                isInAccountCurrency(transaction, account.currency_code);

            // A balance belongs to the account and is held in its currency, so
            // a row in another currency carries none.
            const parsedTransactions = convertRowsToTransactions(
                state.parsedData,
                state.columnMapping,
                state.dateFormat,
                account.currency_code,
                supportedCurrencies,
            ).map((transaction) =>
                ownMoney(transaction)
                    ? transaction
                    : { ...transaction, balance: null },
            );

            const duplicateFlags = await transactionSyncService.checkDuplicates(
                account.id,
                parsedTransactions,
            );

            let transactionsWithDuplicateCheck = parsedTransactions.map(
                (transaction, index) => ({
                    ...transaction,
                    isDuplicate: duplicateFlags[index],
                    selected: !duplicateFlags[index],
                }),
            );

            // When calculate-balances is enabled and no balance column is
            // mapped, derive balances from the reference balance for every
            // distinct transaction date.
            const shouldCalculate =
                state.calculateBalances &&
                !state.columnMapping.balance &&
                state.referenceBalanceDate !== null &&
                state.referenceBalance !== null;

            if (shouldCalculate) {
                const calculatedBalances = calculateBalancesFromTransactions(
                    transactionsWithDuplicateCheck.filter(ownMoney),
                    state.referenceBalanceDate as string,
                    state.referenceBalance as number,
                );

                transactionsWithDuplicateCheck =
                    transactionsWithDuplicateCheck.map((transaction) => ({
                        ...transaction,
                        balance: ownMoney(transaction)
                            ? (calculatedBalances.get(
                                  transaction.transaction_date,
                              ) ??
                              transaction.balance ??
                              null)
                            : null,
                    }));
            }

            if (state.selectedAccountId) {
                void saveImportConfig(state.selectedAccountId, {
                    columnMapping: state.columnMapping,
                    dateFormat: state.dateFormat,
                });
            }

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
        const rows = selectedIndexes.map((index) => state.transactions[index]);

        setImportTotal(rows.length);
        setImportProgress(0);

        if (!selectedAccount) {
            setError('Selected account not found');
            setIsImporting(false);
            return;
        }

        const { imported, errors, successCount, uncategorizedCount } =
            await importTransactions({
                account: selectedAccount,
                rows,
                categories,
                accounts,
                banks,
                automationRules,
                onProgress: setImportProgress,
            });

        // A partial import keeps the drawer open on the same preview, so the
        // rows already created have to be taken out of the selection: pressing
        // Import again would otherwise create every one of them a second time.
        const importedIndexes = new Set(
            selectedIndexes.filter((_, position) => imported[position]),
        );

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

        const errorCount = errors.length;

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
                        'Drop your CSV, Excel, or Numbers file here, or click to browse',
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
                        'Import transactions from CSV, Excel, or Numbers files',
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
                    />
                );

            case ImportStep.MapColumns:
                return (
                    <ImportStepMapping
                        columnOptions={state.columnOptions}
                        columnMapping={state.columnMapping}
                        dateFormat={state.dateFormat}
                        dateFormatDetected={state.dateFormatDetected}
                        parsedData={state.parsedData}
                        rowNumbers={state.rowNumbers}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        supportedCurrencies={supportedCurrencies}
                        calculateBalances={state.calculateBalances}
                        referenceBalance={state.referenceBalance}
                        referenceBalancePrefilled={
                            state.referenceBalancePrefilled
                        }
                        calculateBalancesAvailable={
                            features.calculateBalancesOnImport
                        }
                        onMappingChange={handleMappingChange}
                        onDateFormatChange={handleDateFormatChange}
                        onCalculateBalancesChange={
                            handleCalculateBalancesChange
                        }
                        onReferenceBalanceChange={handleReferenceBalanceChange}
                        onLatestDateChange={handleLatestDateChange}
                        onNext={handlePreviewTransactions}
                        onBack={() => moveToStep(ImportStep.UploadFile)}
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
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
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
