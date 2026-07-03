import {
    index as indexBalances,
    store as storeBalance,
} from '@/actions/App/Http/Controllers/AccountBalanceController';
import AlertError from '@/components/alert-error';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Progress } from '@/components/ui/progress';
import { importKey } from '@/lib/crypto';
import { getCsrfToken } from '@/lib/csrf';
import {
    autoDetectColumns,
    calculateBalancesFromTransactions,
    collectBalancesToImport,
    convertRowsToTransactions,
    parseFile,
} from '@/lib/file-parser';
import {
    loadImportConfig,
    saveImportConfig,
} from '@/lib/import-config-storage';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
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
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ImportStepAccount } from './import-step-account';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';
import { ImportStepUpload } from './import-step-upload';

interface ImportTransactionsDrawerProps {
    accounts?: Account[];
    categories?: Category[];
    banks?: Bank[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onImportComplete?: () => void;
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
    const { locale, features } = usePage<SharedData>().props;
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
    const [state, setState] = useState<ImportState>({
        step: ImportStep.SelectAccount,
        selectedAccountId: null,
        file: null,
        parsedData: [],
        columnHeaders: [],
        columnOptions: [],
        columnMapping: {
            transaction_date: null,
            description: null,
            amount: null,
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
                columnOptions: [],
                columnMapping: {
                    transaction_date: null,
                    description: null,
                    amount: null,
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
            });
            setIsImporting(false);
            setError(null);
            setWasSingleAccountAutoSelected(false);
            setSelectedAccount(null);
        }
    }, [open]);

    const handleAccountSelect = (accountId: UUID) => {
        setState((prev) => ({ ...prev, selectedAccountId: accountId }));
    };

    const handleSingleAccountAutoSelect = (accountId: UUID) => {
        setWasSingleAccountAutoSelected(true);
        handleAccountSelect(accountId);
    };

    const handleFileSelect = async (file: File) => {
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
            const { headers, data, columns, headerRowIndex } =
                await parseFile(file);
            const autoMapping = autoDetectColumns(headers);

            const columnOptions = headers.map((header, index) => {
                const columnData = columns[index] || [];
                const middleIndex = Math.floor(columnData.length / 2);
                const examples = columnData
                    .slice(
                        Math.max(headerRowIndex + 1, middleIndex),
                        Math.max(headerRowIndex + 1, middleIndex) + 3,
                    )
                    .filter(
                        (cell) =>
                            cell !== null &&
                            cell !== undefined &&
                            String(cell).trim() !== '',
                    )
                    .map((cell) => String(cell))
                    .slice(0, 3);

                return {
                    value: header,
                    label: header,
                    examples,
                };
            });

            let detectedFormat = DateFormat.YearMonthDay;
            let formatDetected = false;
            let formatAmbiguous = false;
            if (autoMapping.transaction_date) {
                const { detectDateFormat } = await import('@/lib/file-parser');
                const detected = detectDateFormat(
                    data,
                    autoMapping.transaction_date,
                    locale,
                );
                if (detected) {
                    detectedFormat = detected.format;
                    formatAmbiguous = detected.ambiguous;
                    formatDetected = !detected.ambiguous;
                }
            }

            let finalMapping = autoMapping;
            let finalDateFormat = detectedFormat;

            if (state.selectedAccountId) {
                const savedConfig = loadImportConfig(state.selectedAccountId);

                if (savedConfig) {
                    const isValidMapping = (
                        mapping: ColumnMapping,
                    ): boolean => {
                        const values = Object.values(mapping).filter(
                            (v) => v !== null,
                        );
                        return values.every((value) => {
                            if (Array.isArray(value)) {
                                return value.every((v) =>
                                    headers.includes(v as string),
                                );
                            }
                            return headers.includes(value as string);
                        });
                    };

                    if (isValidMapping(savedConfig.columnMapping)) {
                        finalMapping = savedConfig.columnMapping;
                        finalDateFormat = savedConfig.dateFormat;
                        // Keep the saved format as the default, but still show
                        // the selector when the dates are ambiguous so a
                        // previously-saved wrong format can be corrected.
                        formatDetected = !formatAmbiguous;
                    }
                }
            }

            setState((prev) => ({
                ...prev,
                file,
                parsedData: data,
                columnHeaders: headers,
                columnOptions,
                columnMapping: finalMapping,
                dateFormat: finalDateFormat,
                dateFormatDetected: formatDetected,
            }));
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
            const parsedTransactions = convertRowsToTransactions(
                state.parsedData,
                state.columnMapping,
                state.dateFormat,
            );

            const account = accounts.find(
                (a) => a.id === state.selectedAccountId,
            );

            if (!account) {
                setError('Selected account not found');
                return;
            }

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
                    transactionsWithDuplicateCheck,
                    state.referenceBalanceDate as string,
                    state.referenceBalance as number,
                );

                transactionsWithDuplicateCheck =
                    transactionsWithDuplicateCheck.map((transaction) => ({
                        ...transaction,
                        balance:
                            calculatedBalances.get(
                                transaction.transaction_date,
                            ) ??
                            transaction.balance ??
                            null,
                    }));
            }

            if (state.selectedAccountId) {
                saveImportConfig(state.selectedAccountId, {
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

        const newTransactions = state.transactions.filter((t) => t.selected);
        const total = newTransactions.length;
        setImportTotal(total);
        setImportProgress(0);

        if (!selectedAccount) {
            setError('Selected account not found');
            setIsImporting(false);
            return;
        }

        const createdTransactions: unknown[] = [];
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
                    if (!result.value.hasCategory) {
                        uncategorizedCount++;
                    }
                } else {
                    const errorMessage =
                        result.reason instanceof Error
                            ? result.reason.message
                            : __('Unknown error');

                    console.error(`Transaction ${rowNumber} failed:`, {
                        transaction,
                        error: result.reason,
                        errorMessage,
                        stack:
                            result.reason instanceof Error
                                ? result.reason.stack
                                : undefined,
                    });

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

        const balancesToImport = collectBalancesToImport(newTransactions);

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

        setImportErrors(errors);
        setIsImporting(false);

        const successCount = createdTransactions.length;
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
                              onClick: () =>
                                  router.visit('/transactions/categorize'),
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
                              onClick: () =>
                                  router.visit('/transactions/categorize'),
                          }
                        : undefined,
            });
        } else if (successCount > 0) {
            const message =
                uncategorizedCount > 0
                    ? `${successCount} transaction${successCount !== 1 ? 's' : ''} imported (${uncategorizedCount} uncategorized)`
                    : `${successCount} transaction${successCount !== 1 ? 's' : ''} imported successfully`;
            toast.success(message, {
                action:
                    uncategorizedCount > 0
                        ? {
                              label: 'Categorize',
                              onClick: () =>
                                  router.visit('/transactions/categorize'),
                          }
                        : undefined,
            });
            onOpenChange(false);
        } else {
            toast.error(__('All transactions failed to import'));
        }

        onImportComplete?.();
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
                t.isDuplicate ? t : { ...t, selected },
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
                        columnMapping={state.columnMapping}
                        dateFormat={state.dateFormat}
                        dateFormatDetected={state.dateFormatDetected}
                        parsedData={state.parsedData}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
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
