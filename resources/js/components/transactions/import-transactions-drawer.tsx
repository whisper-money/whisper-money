import AlertError from '@/components/alert-error';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import {
    autoDetectColumns,
    convertRowsToTransactions,
    parseFile,
} from '@/lib/file-parser';
import {
    loadImportConfig,
    saveImportConfig,
} from '@/lib/import-config-storage';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRules } from '@/lib/rule-engine';
import { accountBalanceSyncService } from '@/services/account-balance-sync';
import { accountSyncService } from '@/services/account-sync';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account } from '@/types/account';
import {
    DateFormat,
    ImportStep,
    type ColumnMapping,
    type ImportState,
} from '@/types/import';
import { Progress } from '@/components/ui/progress';
import { Check, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ImportStepAccount } from './import-step-account';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';
import { ImportStepUpload } from './import-step-upload';

interface ImportTransactionsDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: import('@/types/category').Category[];
    accounts: import('@/types/account').Account[];
    banks: import('@/types/account').Bank[];
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
    open,
    onOpenChange,
    categories,
    accounts,
    banks,
}: ImportTransactionsDrawerProps) {
    const { isKeySet } = useEncryptionKey();
    const [isImporting, setIsImporting] = useState(false);
    const [importProgress, setImportProgress] = useState(0);
    const [importTotal, setImportTotal] = useState(0);
    const [importErrors, setImportErrors] = useState<ImportError[]>([]);
    const [error, setError] = useState<string | null>(null);
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
        },
        dateFormat: DateFormat.YearMonthDay,
        dateFormatDetected: false,
        transactions: [],
    });

    useEffect(() => {
        if (state.selectedAccountId) {
            accountSyncService
                .getById(state.selectedAccountId)
                .then((account) => {
                    if (account) {
                        setSelectedAccount(account);
                    }
                });
        }
    }, [state.selectedAccountId]);

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
                },
                dateFormat: DateFormat.YearMonthDay,
                dateFormatDetected: false,
                transactions: [],
            });
            setIsImporting(false);
            setError(null);
            setSelectedAccount(null);
        }
    }, [open]);

    const handleAccountSelect = (accountId: number) => {
        setState((prev) => ({ ...prev, selectedAccountId: accountId }));
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
            if (autoMapping.transaction_date) {
                const { autoDetectDateFormat } = await import(
                    '@/lib/file-parser'
                );
                const detected = autoDetectDateFormat(
                    data,
                    autoMapping.transaction_date,
                );
                if (detected) {
                    detectedFormat = detected;
                    formatDetected = true;
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
                        return values.every((value) =>
                            headers.includes(value as string),
                        );
                    };

                    if (isValidMapping(savedConfig.columnMapping)) {
                        finalMapping = savedConfig.columnMapping;
                        finalDateFormat = savedConfig.dateFormat;
                        formatDetected = true;
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

    const handleMappingChange = (field: keyof ColumnMapping, value: string) => {
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
            const parsedTransactions = convertRowsToTransactions(
                state.parsedData,
                state.columnMapping,
                state.dateFormat,
            );

            const account = await accountSyncService.getById(
                state.selectedAccountId!,
            );

            if (!account) {
                setError('Selected account not found');
                return;
            }

            const duplicateFlags = await transactionSyncService.checkDuplicates(
                account.id,
                parsedTransactions,
            );

            const transactionsWithDuplicateCheck = parsedTransactions.map(
                (transaction, index) => ({
                    ...transaction,
                    isDuplicate: duplicateFlags[index],
                }),
            );

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
        if (!isKeySet) {
            setError('Please unlock your encryption key first');
            return;
        }

        setIsImporting(true);
        setError(null);
        setImportErrors([]);

        const newTransactions = state.transactions.filter(
            (t) => !t.isDuplicate,
        );
        const total = newTransactions.length;
        setImportTotal(total);
        setImportProgress(0);

        if (!selectedAccount) {
            setError('Selected account not found');
            setIsImporting(false);
            return;
        }

        const createdTransactions: any[] = [];
        const errors: ImportError[] = [];
        const keyString = getStoredKey();
        const key = keyString ? await importKey(keyString) : null;
        const rules = key ? await automationRuleSyncService.getAll() : [];

        for (let i = 0; i < newTransactions.length; i++) {
            const transaction = newTransactions[i];
            const rowNumber = i + 1;

            try {
                const { encrypted, iv } =
                    await transactionSyncService.encryptDescription(
                        transaction.description,
                    );

                const transactionData = {
                    user_id:
                        (selectedAccount as Account & { user_id?: number })
                            .user_id || 0,
                    account_id: selectedAccount.id,
                    category_id: null,
                    description: encrypted,
                    description_iv: iv,
                    transaction_date: transaction.transaction_date,
                    amount: transaction.amount.toString(),
                    currency_code: selectedAccount.currency_code,
                    notes: null,
                    notes_iv: null,
                };

                const createdTransaction =
                    await transactionSyncService.create(transactionData);

                createdTransactions.push(createdTransaction);
            } catch (err) {
                errors.push({
                    rowNumber,
                    transaction: {
                        date: transaction.transaction_date,
                        description: transaction.description,
                        amount: transaction.amount.toString(),
                    },
                    error:
                        err instanceof Error
                            ? err.message
                            : 'Unknown error',
                });
            }

            setImportProgress(i + 1);
        }

        if (key && rules.length > 0 && createdTransactions.length > 0) {
            for (const createdTransaction of createdTransactions) {
                try {
                    const decryptedDescription = await decrypt(
                        createdTransaction.description,
                        key,
                        createdTransaction.description_iv,
                    );

                    const account = accounts.find(
                        (a) => a.id === createdTransaction.account_id,
                    );
                    const category = createdTransaction.category_id
                        ? categories.find(
                            (c) => c.id === createdTransaction.category_id,
                        )
                        : null;

                    const decryptedTransaction = {
                        ...createdTransaction,
                        decryptedDescription,
                        decryptedNotes: null,
                        account,
                        category: category || null,
                        bank: account?.bank?.id
                            ? banks.find((b) => b.id === account.bank.id)
                            : undefined,
                    };

                    const result = evaluateRules(
                        decryptedTransaction,
                        rules,
                        categories,
                        accounts,
                        banks,
                    );

                    if (result) {
                        let finalNotes = createdTransaction.notes;
                        let finalNotesIv = createdTransaction.notes_iv;

                        if (result.note && result.noteIv) {
                            finalNotes = result.note;
                            finalNotesIv = result.noteIv;
                        }

                        await transactionSyncService.update(
                            createdTransaction.id,
                            {
                                category_id: result.categoryId,
                                notes: finalNotes,
                                notes_iv: finalNotesIv,
                            },
                        );
                    }
                } catch (ruleError) {
                    console.warn('Failed to apply automation rules to transaction:', ruleError);
                }
            }
        }

        const balancesToImport = new Map<string, number>();
        for (const transaction of newTransactions) {
            if (transaction.balance !== null && transaction.balance !== undefined) {
                balancesToImport.set(transaction.transaction_date, transaction.balance);
            }
        }

        if (balancesToImport.size > 0) {
            try {
                const balanceRecords = Array.from(balancesToImport.entries()).map(
                    ([date, balance]) => ({
                        account_id: selectedAccount.id,
                        balance_date: date,
                        balance,
                    }),
                );

                await accountBalanceSyncService.createMany(balanceRecords);
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
            toast.success(
                `${successCount} transaction${successCount !== 1 ? 's' : ''} imported successfully`,
                {
                    icon: <Check className="h-4 w-4" />,
                },
            );
            onOpenChange(false);
        } else if (successCount > 0 && errorCount > 0) {
            toast.warning(
                `${successCount} transaction${successCount !== 1 ? 's' : ''} imported, ${errorCount} failed`,
            );
        } else if (successCount > 0) {
            toast.success(
                `${successCount} transaction${successCount !== 1 ? 's' : ''} imported successfully`,
                {
                    icon: <Check className="h-4 w-4" />,
                },
            );
            onOpenChange(false);
        } else {
            toast.error('All transactions failed to import');
        }

        transactionSyncService.sync().catch((syncError) => {
            console.error('Failed to sync transactions with backend:', syncError);
        });

        accountBalanceSyncService.sync().catch((syncError) => {
            console.error('Failed to sync balances with backend:', syncError);
        });
    };

    const moveToStep = (step: ImportStep) => {
        setState((prev) => ({ ...prev, step }));
    };

    const getStepInfo = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return {
                    title: 'Select Account',
                    description:
                        'Choose the account where transactions will be imported',
                };
            case ImportStep.UploadFile:
                return {
                    title: 'Upload File',
                    description:
                        'Drop your CSV or Excel file here, or click to browse',
                };
            case ImportStep.MapColumns:
                return {
                    title: 'Map Columns',
                    description:
                        'Match your file columns to transaction fields',
                };
            case ImportStep.Preview:
                return {
                    title: 'Preview Transactions',
                    description: 'Review transactions before importing',
                };
            default:
                if (isImporting) {
                    return {
                        title: 'Importing Transactions',
                        description: 'Please wait while we import your transactions',
                    };
                }

                return {
                    title: 'Import Transactions',
                    description: 'Import transactions from CSV or Excel files',
                };
        }
    };

    const renderStep = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return (
                    <ImportStepAccount
                        selectedAccountId={state.selectedAccountId}
                        onAccountSelect={handleAccountSelect}
                        onNext={() => moveToStep(ImportStep.UploadFile)}
                    />
                );
            case ImportStep.UploadFile:
                return (
                    <ImportStepUpload
                        file={state.file}
                        onFileSelect={handleFileSelect}
                        onNext={() => moveToStep(ImportStep.MapColumns)}
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
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        onMappingChange={handleMappingChange}
                        onDateFormatChange={handleDateFormatChange}
                        onNext={handlePreviewTransactions}
                        onBack={() => moveToStep(ImportStep.UploadFile)}
                    />
                );
            case ImportStep.Preview:
                return (
                    <ImportStepPreview
                        transactions={state.transactions}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        onConfirm={handleConfirmImport}
                        onBack={() => moveToStep(ImportStep.MapColumns)}
                        isImporting={isImporting}
                    />
                );
            default:
                return null;
        }
    };

    const renderImportProgress = () => {
        const percentage = importTotal > 0 ? (importProgress / importTotal) * 100 : 0;

        return (
            <div className="flex flex-col gap-6">
                <div className="space-y-4">
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            {importProgress} of {importTotal} transactions imported
                        </span>
                        <span>{Math.round(percentage)}%</span>
                    </div>
                    <Progress value={percentage} className="h-4" />
                </div>

                {importErrors.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-medium text-destructive">
                                Errors ({importErrors.length})
                            </h3>
                        </div>
                        <div className="max-h-[300px] overflow-y-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-muted">
                                    <tr className="border-b">
                                        <th className="px-4 py-2 text-left font-medium">
                                            Row
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Date
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Description
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Amount
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Error
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
                                            <td className="px-4 py-2 max-w-[200px] truncate">
                                                {error.transaction.description}
                                            </td>
                                            <td className="px-4 py-2 font-mono">
                                                {error.transaction.amount}
                                            </td>
                                            <td className="px-4 py-2 text-destructive max-w-[200px] truncate">
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
                <div className="mx-auto w-full max-w-3xl overflow-y-auto p-6">
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
