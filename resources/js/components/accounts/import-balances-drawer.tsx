import { store } from '@/actions/App/Http/Controllers/AccountBalanceController';
import AlertError from '@/components/alert-error';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Progress } from '@/components/ui/progress';
import {
    loadBalanceImportConfig,
    saveBalanceImportConfig,
} from '@/lib/balance-import-config-storage';
import { getCsrfToken } from '@/lib/csrf';
import {
    autoDetectDateFormat,
    parseAmount,
    parseDate,
    parseFile,
} from '@/lib/file-parser';
import { type SharedData } from '@/types';
import { supportsInvestedAmount, type Account } from '@/types/account';
import {
    BalanceImportStep,
    type BalanceColumnMapping,
    type BalanceImportState,
    type ParsedBalance,
} from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import type { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ImportBalanceStepAccount } from './import-balances/import-balance-step-account';
import { ImportBalanceStepMapping } from './import-balances/import-balance-step-mapping';
import { ImportBalanceStepPreview } from './import-balances/import-balance-step-preview';
import { ImportBalanceStepUpload } from './import-balances/import-balance-step-upload';

interface ImportBalancesDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    accounts?: Account[];
    account?: Account;
    accountId?: UUID;
    onSuccess?: () => void;
}

interface ImportError {
    rowNumber: number;
    balance: {
        date: string;
        amount: string;
    };
    error: string;
}

export function ImportBalancesDrawer({
    open,
    onOpenChange,
    accounts = [],
    account: providedAccount,
    accountId,
    onSuccess,
}: ImportBalancesDrawerProps) {
    const { locale, auth } = usePage<SharedData>().props;
    const userCurrencyCode = auth.user.currency_code;
    const [isImporting, setIsImporting] = useState(false);
    const [importProgress, setImportProgress] = useState(0);
    const [importTotal, setImportTotal] = useState(0);
    const [importErrors, setImportErrors] = useState<ImportError[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(
        null,
    );

    const initialStep = accountId
        ? BalanceImportStep.UploadFile
        : BalanceImportStep.SelectAccount;

    const [state, setState] = useState<BalanceImportState>({
        step: initialStep,
        selectedAccountId: accountId ?? null,
        file: null,
        parsedData: [],
        columnHeaders: [],
        columnOptions: [],
        columnMapping: {
            balance_date: null,
            balance: null,
            invested_amount: null,
        },
        dateFormat: DateFormat.YearMonthDay,
        dateFormatDetected: false,
        balances: [],
    });

    useEffect(() => {
        if (open && state.selectedAccountId) {
            const account =
                (providedAccount?.id === state.selectedAccountId
                    ? providedAccount
                    : undefined) ??
                accounts.find((a) => a.id === state.selectedAccountId);
            if (account) {
                setSelectedAccount(account);
            }
        }
    }, [open, state.selectedAccountId, accounts, providedAccount]);

    useEffect(() => {
        if (!open) {
            setState({
                step: initialStep,
                selectedAccountId: accountId ?? null,
                file: null,
                parsedData: [],
                columnHeaders: [],
                columnOptions: [],
                columnMapping: {
                    balance_date: null,
                    balance: null,
                    invested_amount: null,
                },
                dateFormat: DateFormat.YearMonthDay,
                dateFormatDetected: false,
                balances: [],
            });
            setIsImporting(false);
            setError(null);
            setSelectedAccount(null);
            setImportErrors([]);
            setImportProgress(0);
            setImportTotal(0);
        }
    }, [open, accountId, initialStep]);

    const handleAccountSelect = (selectedAccountId: UUID) => {
        setState((prev) => ({ ...prev, selectedAccountId }));
    };

    const autoDetectBalanceColumns = (
        headers: string[],
    ): BalanceColumnMapping => {
        const mapping: BalanceColumnMapping = {
            balance_date: null,
            balance: null,
            invested_amount: null,
        };

        if (!headers || headers.length === 0) {
            return mapping;
        }

        const lowerHeaders = headers.map((h) => {
            if (h === null || h === undefined) {
                return '';
            }
            return String(h).toLowerCase();
        });

        const datePatterns = [
            'date',
            'balance date',
            'fecha',
            'balance_date',
            'f. valor',
        ];

        const balancePatterns = [
            'balance',
            'saldo',
            'amount',
            'monto',
            'value',
            'valor',
            'total',
        ];

        const investedPatterns = [
            'invested',
            'invested_amount',
            'invested amount',
            'cost',
            'cost basis',
            'invertido',
            'coste',
        ];

        for (let i = 0; i < lowerHeaders.length; i++) {
            const header = lowerHeaders[i];
            const originalHeader = headers[i];

            if (!header || typeof header !== 'string') {
                continue;
            }

            if (
                !mapping.balance_date &&
                datePatterns.some((p) => header.includes(p))
            ) {
                mapping.balance_date = originalHeader;
            }

            if (
                !mapping.balance &&
                balancePatterns.some((p) => header.includes(p))
            ) {
                mapping.balance = originalHeader;
            }

            if (
                !mapping.invested_amount &&
                investedPatterns.some((p) => header.includes(p))
            ) {
                mapping.invested_amount = originalHeader;
            }
        }

        return mapping;
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
            const autoMapping = autoDetectBalanceColumns(headers);

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
            if (autoMapping.balance_date) {
                const detected = autoDetectDateFormat(
                    data,
                    autoMapping.balance_date,
                    locale,
                );
                if (detected) {
                    detectedFormat = detected;
                    formatDetected = true;
                }
            }

            let finalMapping = autoMapping;
            let finalDateFormat = detectedFormat;

            if (state.selectedAccountId) {
                const savedConfig = loadBalanceImportConfig(
                    state.selectedAccountId,
                );

                if (savedConfig) {
                    const isValidMapping = (
                        mapping: BalanceColumnMapping,
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

    const handleMappingChange = (
        field: keyof BalanceColumnMapping,
        value: string,
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

    const handlePreviewBalances = () => {
        try {
            const parsedBalances: ParsedBalance[] = [];

            for (const row of state.parsedData) {
                if (
                    !state.columnMapping.balance_date ||
                    !state.columnMapping.balance
                ) {
                    continue;
                }

                const date = parseDate(
                    row[state.columnMapping.balance_date] as string | number,
                    state.dateFormat,
                );
                const balance = parseAmount(
                    row[state.columnMapping.balance] as string | number,
                );

                if (!date || balance === null) {
                    continue;
                }

                const formattedDate = date.toISOString().split('T')[0];

                let investedAmount: number | null = null;
                if (state.columnMapping.invested_amount) {
                    const rawInvested = parseAmount(
                        row[state.columnMapping.invested_amount] as
                            | string
                            | number,
                    );
                    if (rawInvested !== null) {
                        investedAmount = Math.round(rawInvested * 100);
                    }
                }

                parsedBalances.push({
                    balance_date: formattedDate,
                    balance: Math.round(balance * 100),
                    invested_amount: investedAmount,
                });
            }

            if (state.selectedAccountId) {
                saveBalanceImportConfig(state.selectedAccountId, {
                    columnMapping: state.columnMapping,
                    dateFormat: state.dateFormat,
                });
            }

            setState((prev) => ({
                ...prev,
                balances: parsedBalances,
                step: BalanceImportStep.Preview,
            }));
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to process balances',
            );
        }
    };

    const handleConfirmImport = async () => {
        setIsImporting(true);
        setError(null);
        setImportErrors([]);

        const total = state.balances.length;
        setImportTotal(total);
        setImportProgress(0);

        if (!selectedAccount) {
            setError('Selected account not found');
            setIsImporting(false);
            return;
        }

        const createdBalances: unknown[] = [];
        const errors: ImportError[] = [];

        const BATCH_SIZE = 50;
        let processedCount = 0;

        const xsrfToken = getCsrfToken();

        for (let i = 0; i < state.balances.length; i += BATCH_SIZE) {
            const batch = state.balances.slice(i, i + BATCH_SIZE);

            const batchResults = await Promise.allSettled(
                batch.map(async (balance, batchIndex) => {
                    const rowNumber = i + batchIndex + 1;

                    const response = await fetch(
                        store.url(selectedAccount.id),
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-XSRF-TOKEN': xsrfToken,
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({
                                balance_date: balance.balance_date,
                                balance: balance.balance,
                                ...(balance.invested_amount !== null
                                    ? {
                                          invested_amount:
                                              balance.invested_amount,
                                      }
                                    : {}),
                            }),
                        },
                    );

                    if (!response.ok) {
                        const data = await response.json();
                        throw new Error(
                            data.message || 'Failed to create balance',
                        );
                    }

                    const data = await response.json();
                    return {
                        success: true,
                        balance: data.data,
                        rowNumber,
                    };
                }),
            );

            batchResults.forEach((result, batchIndex) => {
                const balance = batch[batchIndex];
                const rowNumber = i + batchIndex + 1;

                if (result.status === 'fulfilled') {
                    createdBalances.push(result.value.balance);
                } else {
                    const errorMessage =
                        result.reason instanceof Error
                            ? result.reason.message
                            : __('Unknown error');

                    errors.push({
                        rowNumber,
                        balance: {
                            date: balance.balance_date,
                            amount: (balance.balance / 100).toString(),
                        },
                        error: errorMessage,
                    });
                }
            });

            processedCount += batch.length;
            setImportProgress(processedCount);
        }

        setImportErrors(errors);
        setIsImporting(false);

        const successCount = createdBalances.length;
        const errorCount = errors.length;
        const isLoan = selectedAccount?.type === 'loan';
        const term = isLoan ? 'owed amount' : 'balance';
        const termPlural = isLoan ? 'owed amounts' : 'balances';

        if (errorCount === 0 && successCount > 0) {
            toast.success(
                `${successCount} ${successCount !== 1 ? termPlural : term} imported successfully`,
                {
                    icon: <Check className="h-4 w-4" />,
                },
            );
            onSuccess?.();
            onOpenChange(false);
        } else if (successCount > 0 && errorCount > 0) {
            toast.warning(
                `${successCount} ${successCount !== 1 ? termPlural : term} imported, ${errorCount} failed`,
            );
            onSuccess?.();
        } else if (successCount > 0) {
            toast.success(
                `${successCount} ${successCount !== 1 ? termPlural : term} imported successfully`,
                {
                    icon: <Check className="h-4 w-4" />,
                },
            );
            onSuccess?.();
            onOpenChange(false);
        } else {
            toast.error(
                isLoan
                    ? 'All owed amounts failed to import'
                    : 'All balances failed to import',
            );
        }
    };

    const moveToStep = (step: BalanceImportStep) => {
        setState((prev) => ({ ...prev, step }));
    };

    const getStepInfo = () => {
        const isLoan = selectedAccount?.type === 'loan';

        switch (state.step) {
            case BalanceImportStep.SelectAccount:
                return {
                    title: 'Select Account',
                    description: isLoan
                        ? 'Choose the account where owed amounts will be imported'
                        : 'Choose the account where balances will be imported',
                };
            case BalanceImportStep.UploadFile:
                return {
                    title: 'Upload File',
                    description:
                        'Drop your CSV or Excel file here, or click to browse',
                };
            case BalanceImportStep.MapColumns:
                return {
                    title: 'Map Columns',
                    description: isLoan
                        ? 'Match your file columns to owed amount fields'
                        : 'Match your file columns to balance fields',
                };
            case BalanceImportStep.Preview:
                return {
                    title: isLoan ? 'Preview Owed Amounts' : 'Preview Balances',
                    description: isLoan
                        ? 'Review owed amounts before importing'
                        : 'Review balances before importing',
                };
            default:
                if (isImporting) {
                    return {
                        title: isLoan
                            ? 'Importing Owed Amounts'
                            : 'Importing Balances',
                        description: isLoan
                            ? 'Please wait while we import your owed amounts'
                            : 'Please wait while we import your balances',
                    };
                }

                return {
                    title: isLoan ? 'Import Owed Amounts' : 'Import Balances',
                    description: isLoan
                        ? 'Import owed amounts from CSV or Excel files'
                        : 'Import balances from CSV or Excel files',
                };
        }
    };

    const handleBack = () => {
        if (state.step === BalanceImportStep.UploadFile && accountId) {
            onOpenChange(false);
        } else if (state.step === BalanceImportStep.UploadFile) {
            moveToStep(BalanceImportStep.SelectAccount);
        } else if (state.step === BalanceImportStep.MapColumns) {
            moveToStep(BalanceImportStep.UploadFile);
        } else if (state.step === BalanceImportStep.Preview) {
            moveToStep(BalanceImportStep.MapColumns);
        }
    };

    const renderStep = () => {
        const showInvestedAmount = selectedAccount
            ? supportsInvestedAmount(selectedAccount)
            : false;

        switch (state.step) {
            case BalanceImportStep.SelectAccount:
                return (
                    <ImportBalanceStepAccount
                        selectedAccountId={state.selectedAccountId}
                        onAccountSelect={handleAccountSelect}
                        onNext={() => moveToStep(BalanceImportStep.UploadFile)}
                    />
                );

            case BalanceImportStep.UploadFile:
                return (
                    <ImportBalanceStepUpload
                        file={state.file}
                        onFileSelect={handleFileSelect}
                        onNext={() => moveToStep(BalanceImportStep.MapColumns)}
                        onBack={handleBack}
                    />
                );

            case BalanceImportStep.MapColumns:
                return (
                    <ImportBalanceStepMapping
                        columnOptions={state.columnOptions}
                        columnMapping={state.columnMapping}
                        dateFormat={state.dateFormat}
                        dateFormatDetected={state.dateFormatDetected}
                        parsedData={state.parsedData}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        investedAmountCurrencyCode={userCurrencyCode}
                        showInvestedAmount={showInvestedAmount}
                        isLoan={selectedAccount?.type === 'loan'}
                        onMappingChange={handleMappingChange}
                        onDateFormatChange={handleDateFormatChange}
                        onNext={handlePreviewBalances}
                        onBack={handleBack}
                    />
                );

            case BalanceImportStep.Preview:
                return (
                    <ImportBalanceStepPreview
                        balances={state.balances}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        investedAmountCurrencyCode={userCurrencyCode}
                        showInvestedAmount={showInvestedAmount}
                        isLoan={selectedAccount?.type === 'loan'}
                        onConfirm={handleConfirmImport}
                        onBack={handleBack}
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
        const isLoan = selectedAccount?.type === 'loan';

        return (
            <div className="flex flex-col gap-6">
                <div className="space-y-4">
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            {importProgress} of {importTotal}{' '}
                            {isLoan
                                ? __('owed amounts imported')
                                : __('balances imported')}
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
                                            {isLoan
                                                ? __('Owed Amount')
                                                : __('Balance')}
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
                                                {error.balance.date}
                                            </td>
                                            <td className="px-4 py-2 font-mono">
                                                {error.balance.amount}
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
