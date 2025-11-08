import { useState, useEffect } from 'react';
import {
    Drawer,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
    DrawerDescription,
} from '@/components/ui/drawer';
import { ImportStepAccount } from './import-step-account';
import { ImportStepUpload } from './import-step-upload';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';
import {
    ImportStep,
    DateFormat,
    type ImportState,
    type ColumnMapping,
} from '@/types/import';
import { type Account } from '@/types/account';
import {
    parseFile,
    autoDetectColumns,
    convertRowsToTransactions,
} from '@/lib/file-parser';
import { transactionSyncService } from '@/services/transaction-sync';
import { accountSyncService } from '@/services/account-sync';
import { useSyncContext } from '@/contexts/sync-context';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import AlertError from '@/components/alert-error';

interface ImportTransactionsDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function ImportTransactionsDrawer({
    open,
    onOpenChange,
}: ImportTransactionsDrawerProps) {
    const { sync } = useSyncContext();
    const { isKeySet } = useEncryptionKey();
    const [isImporting, setIsImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(null);
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
            accountSyncService.getById(state.selectedAccountId).then((account) => {
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
            const { headers, data, columns, headerRowIndex } = await parseFile(file);
            const autoMapping = autoDetectColumns(headers);

            const columnOptions = headers.map((header, index) => {
                const columnData = columns[index] || [];
                const middleIndex = Math.floor(columnData.length / 2);
                const examples = columnData
                    .slice(Math.max(headerRowIndex + 1, middleIndex), Math.max(headerRowIndex + 1, middleIndex) + 3)
                    .filter(cell => cell !== null && cell !== undefined && String(cell).trim() !== '')
                    .map(cell => String(cell))
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
                const { autoDetectDateFormat } = await import('@/lib/file-parser');
                const detected = autoDetectDateFormat(data, autoMapping.transaction_date);
                if (detected) {
                    detectedFormat = detected;
                    formatDetected = true;
                }
            }

            setState((prev) => ({
                ...prev,
                file,
                parsedData: data,
                columnHeaders: headers,
                columnOptions,
                columnMapping: autoMapping,
                dateFormat: detectedFormat,
                dateFormatDetected: formatDetected,
            }));
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to parse file'
            );
        }
    };

    const handleMappingChange = (
        field: keyof ColumnMapping,
        value: string
    ) => {
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
                state.dateFormat
            );

            const account = await accountSyncService.getById(
                state.selectedAccountId!
            );

            if (!account) {
                setError('Selected account not found');
                return;
            }

            const transactionsWithDuplicateCheck = await Promise.all(
                parsedTransactions.map(async (transaction) => {
                    const isDuplicate = await transactionSyncService.isDuplicate(
                        account.id,
                        transaction.transaction_date,
                        transaction.amount,
                        transaction.description
                    );

                    return {
                        ...transaction,
                        isDuplicate,
                    };
                })
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
                    : 'Failed to process transactions'
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

        try {
            if (!selectedAccount) {
                throw new Error('Selected account not found');
            }

            const newTransactions = state.transactions.filter(
                (t) => !t.isDuplicate
            );

            const transactionsToImport = await Promise.all(
                newTransactions.map(async (transaction) => {
                    const { encrypted, iv } =
                        await transactionSyncService.encryptDescription(
                            transaction.description
                        );

                    return {
                        user_id: (selectedAccount as Account & { user_id?: number }).user_id || 0,
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
                })
            );

            await transactionSyncService.createMany(transactionsToImport);

            sync();

            onOpenChange(false);
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to import transactions'
            );
        } finally {
            setIsImporting(false);
        }
    };

    const moveToStep = (step: ImportStep) => {
        setState((prev) => ({ ...prev, step }));
    };

    const getStepInfo = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return {
                    title: 'Select Account',
                    description: 'Choose the account where transactions will be imported',
                };
            case ImportStep.UploadFile:
                return {
                    title: 'Upload File',
                    description: 'Drop your CSV or Excel file here, or click to browse',
                };
            case ImportStep.MapColumns:
                return {
                    title: 'Map Columns',
                    description: 'Match your file columns to transaction fields',
                };
            case ImportStep.Preview:
                return {
                    title: 'Preview Transactions',
                    description: 'Review transactions before importing',
                };
            default:
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

    const stepInfo = getStepInfo();

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto w-full max-w-3xl overflow-y-auto p-6">
                    <DrawerHeader className="px-0">
                        <DrawerTitle>{stepInfo.title}</DrawerTitle>
                        <DrawerDescription>{stepInfo.description}</DrawerDescription>
                    </DrawerHeader>
                    {error && (
                        <div className="mt-4">
                            <AlertError errors={[error]} />
                        </div>
                    )}
                    <div className="mt-4">{renderStep()}</div>
                </div>
            </DrawerContent>
        </Drawer>
    );
}

