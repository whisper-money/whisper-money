import { categorize } from '@/actions/App/Http/Controllers/TransactionController';
import { AiColumnDetection } from '@/components/ai-column-detection';
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
import { useAiColumnSuggestion } from '@/hooks/use-ai-column-suggestion';
import { accountHasBalance } from '@/hooks/use-owns-balance-updates';
import {
    suggestedTransactionMapping,
    TRANSACTION_FIELDS,
} from '@/lib/ai-import-mapping';
import {
    brokerCashAccountId,
    brokerCurrency,
    brokerPreviewRows,
    readBrokerImport,
    runBrokerImport,
    withBrokerAccountCreated,
    withBrokerCashAccount,
    type BrokerAccountRole,
    type BrokerImport,
} from '@/lib/broker-imports/broker-import';
import { rememberBrokerImport } from '@/lib/broker-imports/broker-memory';
import { describeColumns } from '@/lib/column-shape';
import {
    convertRowsToTransactions,
    isInAccountCurrency,
} from '@/lib/file-parser';
import { loadBrokerLinks, saveImportConfig } from '@/lib/import-config-storage';
import {
    applyStoredImportConfig,
    applyTransactionSuggestion,
    importTransactions,
    parseImportFile,
    type ImportOutcome,
} from '@/lib/transaction-import';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import {
    DateFormat,
    ImportStep,
    type ColumnMapping,
    type ImportState,
    type ParsedRow,
    type ParsedTransaction,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ImportStepAccount } from './import-step-account';
import {
    BrokerFileNotice,
    BrokerImportSummary,
    brokerStepInfo,
    ImportStepBroker,
} from './import-step-broker';
import { MyInvestorSecuritiesPreview } from './import-step-broker-myinvestor';
import { ImportStepMapping } from './import-step-mapping';
import { ImportStepPreview } from './import-step-preview';

interface ImportTransactionsDrawerProps {
    accounts?: Account[];
    categories?: Category[];
    banks?: Bank[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The account to start on, e.g. the one an emailed import link names. */
    initialAccountId?: UUID | null;
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
        transaction_type: null,
        expense_types: [],
    },
    dateFormat: DateFormat.YearMonthDay,
    dateFormatDetected: false,
    transactions: [],
};

/** The rows as the preview takes them: one the account already holds starts unselected. */
async function withDuplicateFlags(
    accountId: UUID | null,
    rows: ParsedTransaction[],
): Promise<ParsedTransaction[]> {
    const duplicateFlags = accountId
        ? await transactionSyncService.checkDuplicates(accountId, rows)
        : rows.map(() => false);

    return rows.map((transaction, index) => ({
        ...transaction,
        isDuplicate: duplicateFlags[index],
        selected: !duplicateFlags[index],
    }));
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
    initialAccountId = null,
    onImportComplete,
}: ImportTransactionsDrawerProps) {
    const { locale, currencies, features } = usePage<SharedData>().props;
    const brokerImportsEnabled = features?.brokerImports === true;
    const supportedCurrencies = useMemo(
        () => currencies.accounts.map((currency) => currency.code),
        [currencies],
    );
    const [isImporting, setIsImporting] = useState(false);
    // A file that only writes balances has no rows to count through.
    const [isSavingBalances, setIsSavingBalances] = useState(false);
    const [importProgress, setImportProgress] = useState(0);
    const [importTotal, setImportTotal] = useState(0);
    const [importErrors, setImportErrors] = useState<ImportError[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [selectedAccount, setSelectedAccount] = useState<Account | null>(
        null,
    );
    const [state, setState] = useState<ImportState>(EMPTY_STATE);
    // A recognised broker file, imported through its own step. Only ever set
    // with the broker imports flag on.
    const [broker, setBroker] = useState<BrokerImport | null>(null);
    // Accounts this import made, kept so a retry reuses them.
    const [createdAccounts, setCreatedAccounts] = useState<Account[]>([]);
    const knownAccounts = useMemo(
        () => [...accounts, ...createdAccounts],
        [accounts, createdAccounts],
    );
    // Bumped on every file change, so a slow broker read cannot land over a
    // file picked (or removed) after it.
    const brokerRead = useRef(0);
    const ai = useAiColumnSuggestion();
    const { begin: resetAi } = ai;
    // A credit card has no balance, so the import neither asks for one nor
    // carries one over from the file.
    const tracksBalance = selectedAccount
        ? accountHasBalance(selectedAccount)
        : true;

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
        if (open && initialAccountId) {
            setState((prev) =>
                prev.selectedAccountId
                    ? prev
                    : { ...prev, selectedAccountId: initialAccountId },
            );
        }
    }, [open, initialAccountId]);

    useEffect(() => {
        if (!open) {
            setState(EMPTY_STATE);
            setIsImporting(false);
            setIsSavingBalances(false);
            setError(null);
            setSelectedAccount(null);
            setBroker(null);
            setCreatedAccounts([]);
            resetAi();
        }
    }, [open, resetAi]);

    const handleAccountSelect = (accountId: UUID) => {
        setState((prev) => ({ ...prev, selectedAccountId: accountId }));
        // A broker file already read follows the account picked for it.
        setBroker((prev) => prev && withBrokerCashAccount(prev, accountId));
    };

    const handleImportWithoutAccount = () => {
        setError(null);
        setSelectedAccount(null);
        // A file read for the account picked before is read again without it.
        setBroker(null);
        brokerRead.current++;
        setState((prev) => ({
            ...prev,
            selectedAccountId: null,
            file: null,
            step: ImportStep.UploadFile,
        }));
    };

    /**
     * Take the file through its broker's step when it is a broker export.
     * True when the file is dealt with here: a broker file, or any other file
     * picked with no account to import it into, which sends the user back to
     * pick one.
     */
    const handleBrokerFile = async (file: File): Promise<boolean> => {
        const read = brokerRead.current;

        try {
            const recognised = await readBrokerImport(
                file,
                accounts,
                state.selectedAccountId,
                loadBrokerLinks,
            );

            if (read !== brokerRead.current) {
                return true;
            }

            if (recognised) {
                setBroker(recognised);
                setState((prev) => ({ ...prev, file }));

                return true;
            }
        } catch (err) {
            // Not a file the broker reader understands: the column mapping
            // reads it, or says why it cannot.
            console.warn('Broker file detection failed:', err);
        }

        if (state.selectedAccountId) {
            return false;
        }

        setState((prev) => ({
            ...prev,
            file: null,
            step: ImportStep.SelectAccount,
        }));
        setError(
            filterTransactionalAccounts(accounts).length === 0
                ? __(
                      'Only a Trade Republic or MyInvestor file can be imported without an account. Create the account this file belongs to first, then import it.',
                  )
                : __(
                      'Only a Trade Republic or MyInvestor file can be imported without an account. Pick the account this file belongs to, then upload it again.',
                  ),
        );

        return true;
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
        description: ColumnMapping['description'],
        everything: boolean,
        token?: number,
    ) => {
        const columns = describeColumns(headers, rows);

        void ai.request<ColumnMapping>({
            kind: 'transaction',
            columns,
            accountId: state.selectedAccountId,
            everything,
            token,
            toMapping: (suggestions) =>
                suggestedTransactionMapping(suggestions, columns, description),
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
        // Whatever the last file failed on no longer applies to this one.
        setError(null);
        setBroker(null);
        brokerRead.current++;
        const token = ai.begin();

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

        if (brokerImportsEnabled && (await handleBrokerFile(file))) {
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
            const stored = accountId
                ? await applyStoredImportConfig(parsed, accountId)
                : null;

            ai.afterStoredLayout(token, stored, TRANSACTION_FIELDS, () =>
                requestSuggestion(
                    file,
                    parsed.headers,
                    parsed.rows,
                    parsed.mapping.description,
                    false,
                    token,
                ),
            );

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

            // A balance belongs to the account and is held in its currency, so
            // a row in another currency carries none, and neither does any row
            // of a credit card, which has no balance at all (a saved mapping
            // may still name a balance column).
            const carriesBalance = (transaction: ParsedTransaction): boolean =>
                tracksBalance &&
                isInAccountCurrency(transaction, account.currency_code);

            const parsedTransactions = convertRowsToTransactions(
                state.parsedData,
                state.columnMapping,
                state.dateFormat,
                account.currency_code,
                supportedCurrencies,
            ).map((transaction) =>
                carriesBalance(transaction)
                    ? transaction
                    : { ...transaction, balance: null },
            );

            const transactions = await withDuplicateFlags(
                account.id,
                parsedTransactions,
            );

            setState((prev) => ({
                ...prev,
                transactions,
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

    const handleBrokerPreview = async () => {
        if (!broker) {
            return;
        }

        const transactions = await withDuplicateFlags(
            brokerCashAccountId(broker),
            brokerPreviewRows(broker),
        );

        setState((prev) => ({
            ...prev,
            transactions,
            step: ImportStep.Preview,
        }));
    };

    /** An account the broker import just made now stands in for "create" in its plan. */
    const handleBrokerAccountCreated = (
        role: BrokerAccountRole,
        account: Account,
    ) => {
        setCreatedAccounts((prev) => [...prev, account]);
        setBroker(
            (prev) => prev && withBrokerAccountCreated(prev, role, account),
        );
    };

    const importBrokerRows = async (
        current: BrokerImport,
        rows: ParsedTransaction[],
    ): Promise<ImportOutcome | null> => {
        try {
            const { outcome, balancesFailed, accounts } = await runBrokerImport(
                current,
                {
                    rows,
                    accounts: knownAccounts,
                    categories,
                    banks,
                    automationRules,
                    onProgress: setImportProgress,
                    onAccountCreated: handleBrokerAccountCreated,
                },
            );

            // Nothing new came in: the figures of the last import still stand.
            if (outcome.successCount > 0) {
                void rememberBrokerImport(current, accounts);
            }

            if (balancesFailed) {
                toast.error(
                    __(
                        "The transactions are in, but the accounts' daily balances could not be saved",
                    ),
                );
            }

            return outcome;
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : __('Failed to create account. Please try again.'),
            );

            return null;
        }
    };

    /** A file with no transactions: only the portfolio's daily balances are written. */
    const handleConfirmBalancesImport = async (current: BrokerImport) => {
        setIsSavingBalances(true);
        setError(null);

        try {
            const { balancesFailed, accounts } = await runBrokerImport(
                current,
                {
                    rows: [],
                    accounts: knownAccounts,
                    categories,
                    banks,
                    automationRules,
                    onAccountCreated: handleBrokerAccountCreated,
                },
            );

            if (balancesFailed) {
                setError(
                    __(
                        "The portfolio's daily balances could not be saved. Please try again.",
                    ),
                );

                return;
            }

            void rememberBrokerImport(current, accounts);

            toast.success(__('Portfolio history imported'));
            onImportComplete?.(0);
            onOpenChange(false);
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : __('Failed to create account. Please try again.'),
            );
        } finally {
            setIsSavingBalances(false);
        }
    };

    const importMappedRows = async (
        rows: ParsedTransaction[],
    ): Promise<ImportOutcome | null> => {
        if (!selectedAccount) {
            setError('Selected account not found');
            return null;
        }

        const outcome = await importTransactions({
            account: selectedAccount,
            rows,
            categories,
            accounts,
            banks,
            automationRules,
            onProgress: setImportProgress,
        });

        // Only a mapping that actually brought rows in is worth remembering:
        // an abandoned or failed import would leave a bad layout behind.
        if (outcome.successCount > 0) {
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

        return outcome;
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

        const outcome = broker
            ? await importBrokerRows(broker, rows)
            : await importMappedRows(rows);

        if (!outcome) {
            setIsImporting(false);
            return;
        }

        const { imported, errors, successCount, uncategorizedCount } = outcome;

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
            case ImportStep.Broker:
                return broker
                    ? brokerStepInfo(broker)
                    : {
                          title: __('Import Transactions'),
                          description: '',
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

    // A broker file's rows go to the account its step picked, which may not
    // exist until the import makes it.
    const brokerAccountId = broker ? brokerCashAccountId(broker) : null;
    const previewAccount = broker
        ? (knownAccounts.find((account) => account.id === brokerAccountId) ??
          null)
        : selectedAccount;

    const renderStep = () => {
        switch (state.step) {
            case ImportStep.SelectAccount:
                return (
                    <ImportStepAccount
                        accounts={accounts}
                        selectedAccountId={state.selectedAccountId}
                        onAccountSelect={handleAccountSelect}
                        onNext={() => {
                            setError(null);
                            moveToStep(ImportStep.UploadFile);
                        }}
                        onImportWithoutAccount={
                            brokerImportsEnabled
                                ? handleImportWithoutAccount
                                : undefined
                        }
                    />
                );

            case ImportStep.UploadFile:
                return (
                    <ImportStepUpload
                        file={state.file}
                        onFileSelect={handleFileSelect}
                        onNext={() => {
                            moveToStep(
                                broker
                                    ? ImportStep.Broker
                                    : ImportStep.MapColumns,
                            );
                        }}
                        onBack={() => moveToStep(ImportStep.SelectAccount)}
                        notice={broker && <BrokerFileNotice broker={broker} />}
                    />
                );

            case ImportStep.Broker:
                return (
                    broker && (
                        <ImportStepBroker
                            broker={broker}
                            accounts={knownAccounts}
                            onBrokerChange={setBroker}
                            onNext={handleBrokerPreview}
                            onBack={() => moveToStep(ImportStep.UploadFile)}
                        />
                    )
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
                        rowNumbers={state.rowNumbers}
                        currencyCode={selectedAccount?.currency_code || 'USD'}
                        supportedCurrencies={supportedCurrencies}
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
                                        state.columnMapping.description,
                                        true,
                                    )
                                }
                            />
                        }
                    />
                );

            case ImportStep.Preview:
                if (broker?.kind === 'myinvestor_securities') {
                    return (
                        <MyInvestorSecuritiesPreview
                            broker={broker}
                            isImporting={isSavingBalances}
                            onBack={() => moveToStep(ImportStep.Broker)}
                            onConfirm={() =>
                                handleConfirmBalancesImport(broker)
                            }
                        />
                    );
                }

                return (
                    <ImportStepPreview
                        transactions={state.transactions}
                        currencyCode={
                            previewAccount?.currency_code ??
                            (broker ? brokerCurrency(broker) : 'USD')
                        }
                        accountId={previewAccount?.id || ''}
                        onConfirm={handleConfirmImport}
                        onBack={() =>
                            moveToStep(
                                broker
                                    ? ImportStep.Broker
                                    : ImportStep.MapColumns,
                            )
                        }
                        onSelectionChange={handleSelectionChange}
                        onSelectAll={handleSelectAll}
                        isImporting={isImporting}
                        summary={
                            broker && <BrokerImportSummary broker={broker} />
                        }
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
