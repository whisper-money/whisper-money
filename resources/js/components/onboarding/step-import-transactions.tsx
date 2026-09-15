import { StepImportColumns } from '@/components/onboarding/step-import-columns';
import { StepImportFailed } from '@/components/onboarding/step-import-failed';
import {
    StepImportPartial,
    type ImportFailure,
} from '@/components/onboarding/step-import-partial';
import {
    importTargets,
    StepImportPickAccount,
} from '@/components/onboarding/step-import-pick-account';
import { StepImportPreview } from '@/components/onboarding/step-import-preview';
import { StepImportProgress } from '@/components/onboarding/step-import-progress';
import { StepImportUpload } from '@/components/onboarding/step-import-upload';
import { StepScreen } from '@/components/onboarding/step-screen';
import { Spinner } from '@/components/ui/spinner';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import {
    buildMappingReport,
    convertRowsToTransactions,
} from '@/lib/file-parser';
import { saveImportConfig } from '@/lib/import-config-storage';
import { captureEvent } from '@/lib/posthog';
import {
    applyStoredImportConfig,
    importTransactions,
    isSupportedImportFile,
    MAX_IMPORT_FILE_BYTES,
    parseImportFile,
    type ParsedImportFile,
} from '@/lib/transaction-import';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type ColumnMapping, type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * The file import as screens of the flow rather than a modal on top of it.
 *
 * The order is the order of the questions: whose money is this, where is the
 * file, did we read it right, is this what you expected. Only the last one
 * writes anything.
 */
type ImportScreen =
    | 'pick-account'
    | 'upload'
    | 'columns'
    | 'preview'
    | 'importing'
    | 'partial'
    | 'failed';

/** Why a file never made it as far as the column step. */
interface FileRejection {
    fileName: string;
    reason: string;
}

interface StepImportTransactionsProps {
    /** The account just created, preselected when there is a choice to make. */
    account: CreatedAccount | undefined;
    /** False on a plan with no bank connections to offer as a way out. */
    canConnectBank?: boolean;
    onComplete: () => void;
}

/** "PDF is not supported", from whatever the file is actually called. */
function unsupportedReason(fileName: string): string {
    const dot = fileName.lastIndexOf('.');

    return dot === -1
        ? __('That file has no format we recognise')
        : __(':format is not supported', {
              format: fileName.slice(dot + 1).toUpperCase(),
          });
}

export function StepImportTransactions({
    account,
    canConnectBank = true,
    onComplete,
}: StepImportTransactionsProps) {
    const page = usePage<
        SharedData & {
            accounts: Account[];
            categories: Category[];
            banks: Bank[];
            automationRules: AutomationRule[];
        }
    >();
    const { accounts, categories, banks, automationRules, locale, currencies } =
        page.props;

    const [screen, setScreen] = useState<ImportScreen | null>(null);
    const [accountId, setAccountId] = useState<UUID | null>(null);
    const [parsed, setParsed] = useState<ParsedImportFile | null>(null);
    const [rejection, setRejection] = useState<FileRejection | null>(null);
    const [transactions, setTransactions] = useState<ParsedTransaction[]>([]);
    /** Rows the file carried that the mapping could not read, before any import. */
    const [unreadable, setUnreadable] = useState<ImportFailure[]>([]);
    const [failures, setFailures] = useState<ImportFailure[]>([]);
    const [importedCount, setImportedCount] = useState(0);
    const [progress, setProgress] = useState(0);

    const supportedCurrencies = useMemo(
        () => currencies.accounts.map((currency) => currency.code),
        [currencies],
    );
    const targets = useMemo(() => importTargets(accounts), [accounts]);
    const eligible = useMemo(
        () => targets.filter((target) => target.eligible),
        [targets],
    );
    const selectedAccount = useMemo(
        () => accounts.find((one) => one.id === accountId) ?? null,
        [accounts, accountId],
    );

    /**
     * Whether the account this step was opened for has reached the props yet.
     *
     * Not "are there any accounts": a user who connected a bank first arrives
     * here with seven of them already in the props and the one they just typed
     * in missing. Connected accounts are never eligible for a file, so the step
     * read that as nothing to import into and handed itself straight back to
     * the hub — the manual account made, its history never asked for.
     */
    const awaitingAccount =
        account !== undefined && !accounts.some((one) => one.id === account.id);

    // Refresh shared props so the newly created account is available.
    useEffect(() => {
        if (accounts.length === 0 || awaitingAccount) {
            router.reload({
                only: ['accounts', 'categories', 'banks', 'automationRules'],
            });
        }
    }, [accounts.length, awaitingAccount]);

    // Which screen opens the step is a question about the accounts, and they
    // arrive with the reload above — so it is answered once they are here.
    useEffect(() => {
        if (screen !== null || accounts.length === 0 || awaitingAccount) {
            return;
        }

        if (eligible.length === 0) {
            onComplete();
            return;
        }

        const preselected = eligible.some(
            (target) => target.account.id === account?.id,
        )
            ? (account?.id as UUID)
            : eligible[0].account.id;

        setAccountId(preselected);
        // One account is not a question worth asking; more than one is exactly
        // the question the old drawer skipped and got wrong.
        setScreen(eligible.length === 1 ? 'upload' : 'pick-account');
    }, [
        screen,
        accounts.length,
        awaitingAccount,
        eligible,
        account?.id,
        onComplete,
    ]);

    const reject = useCallback((fileName: string, reason: string) => {
        setRejection({ fileName, reason });
        setScreen('failed');
    }, []);

    const handleFileSelect = useCallback(
        async (file: File) => {
            if (!isSupportedImportFile(file)) {
                reject(file.name, unsupportedReason(file.name));
                return;
            }

            if (file.size > MAX_IMPORT_FILE_BYTES) {
                reject(file.name, __('That file is over 10 MB'));
                return;
            }

            try {
                const read = await parseImportFile(file, locale);

                if (read.rows.length === 0) {
                    reject(file.name, __('There are no rows in it'));
                    return;
                }

                setParsed(
                    accountId
                        ? ((await applyStoredImportConfig(read, accountId)) ??
                              read)
                        : read,
                );
                setScreen('columns');
            } catch (error) {
                reject(
                    file.name,
                    __(
                        error instanceof Error
                            ? error.message
                            : 'Failed to parse file',
                    ),
                );
            }
        },
        [accountId, locale, reject],
    );

    const handleMappingChange = useCallback(
        (field: keyof ColumnMapping, column: string | null) => {
            setParsed((previous) =>
                previous
                    ? {
                          ...previous,
                          mapping: { ...previous.mapping, [field]: column },
                      }
                    : previous,
            );
        },
        [],
    );

    const handleColumnsConfirmed = useCallback(async () => {
        if (!parsed || !selectedAccount) {
            return;
        }

        const rows = convertRowsToTransactions(
            parsed.rows,
            parsed.mapping,
            parsed.dateFormat,
            selectedAccount.currency_code,
            supportedCurrencies,
        );

        if (rows.length === 0) {
            reject(parsed.file.name, __('No movements we could read'));
            return;
        }

        // What the mapping had to drop, named by the row number in the file, so
        // the partial screen can send the user to the right line of it.
        const report = buildMappingReport(
            parsed.rows,
            parsed.rowNumbers,
            parsed.mapping,
            parsed.dateFormat,
            selectedAccount.currency_code,
            supportedCurrencies,
        );

        setUnreadable(
            report.problems
                .filter((problem) => problem.severity === 'skipped')
                .map((problem) => ({
                    rowNumber: problem.rowNumber,
                    reason: __(problem.faults[0].reason),
                })),
        );

        const duplicates = await transactionSyncService.checkDuplicates(
            selectedAccount.id,
            rows,
        );

        setTransactions(
            rows.map((row, index) => ({
                ...row,
                isDuplicate: duplicates[index],
                selected: !duplicates[index],
            })),
        );

        void saveImportConfig(selectedAccount.id, {
            columnMapping: parsed.mapping,
            dateFormat: parsed.dateFormat,
        });

        setScreen('preview');
    }, [parsed, selectedAccount, supportedCurrencies, reject]);

    const handleImport = useCallback(async () => {
        if (!selectedAccount) {
            return;
        }

        const rows = transactions.filter((transaction) => transaction.selected);

        setProgress(0);
        setScreen('importing');

        const { errors, successCount } = await importTransactions({
            account: selectedAccount,
            rows,
            categories,
            accounts,
            banks,
            automationRules,
            onProgress: setProgress,
        });

        captureEvent('onboarding_import_completed', {
            transactions_imported: successCount,
            transactions_unreadable: unreadable.length,
            transactions_failed: errors.length,
        });

        // Everything the file offered is in: there is nothing on a results
        // screen the user would do anything about.
        if (errors.length === 0 && unreadable.length === 0) {
            onComplete();
            return;
        }

        setImportedCount(successCount);
        setFailures([
            ...unreadable,
            ...errors.map((error) => ({
                rowNumber: error.rowNumber,
                reason: error.error,
            })),
        ]);
        setScreen('partial');
    }, [
        selectedAccount,
        transactions,
        categories,
        accounts,
        banks,
        automationRules,
        unreadable,
        onComplete,
    ]);

    /**
     * Back to the file, with everything the last one produced dropped. The rows
     * already created are not: they are on the server, and the duplicate check
     * on the next preview is what keeps a retry from creating them twice.
     */
    const startOver = useCallback(() => {
        setParsed(null);
        setRejection(null);
        setTransactions([]);
        setUnreadable([]);
        setFailures([]);
        setScreen('upload');
    }, []);

    if (screen === null) {
        return (
            <StepScreen align="center">
                <Spinner className="size-6 self-center text-muted-foreground" />
            </StepScreen>
        );
    }

    if (screen === 'pick-account') {
        return (
            <StepImportPickAccount
                targets={targets}
                selectedAccountId={accountId}
                onSelect={setAccountId}
                onContinue={() => setScreen('upload')}
            />
        );
    }

    if (screen === 'upload') {
        return (
            <StepImportUpload
                onFileSelect={handleFileSelect}
                onSkip={onComplete}
            />
        );
    }

    if (screen === 'columns' && parsed) {
        return (
            <StepImportColumns
                fileName={parsed.file.name}
                columnOptions={parsed.columnOptions}
                mapping={parsed.mapping}
                onMappingChange={handleMappingChange}
                onConfirm={handleColumnsConfirmed}
                onDifferentFile={startOver}
            />
        );
    }

    if (screen === 'preview') {
        return (
            <StepImportPreview
                transactions={transactions}
                currencyCode={selectedAccount?.currency_code ?? 'USD'}
                locale={locale}
                onConfirm={handleImport}
                onDifferentFile={startOver}
            />
        );
    }

    if (screen === 'importing') {
        return (
            <StepImportProgress
                accountName={selectedAccount?.name ?? __('your account')}
                imported={progress}
                total={
                    transactions.filter((transaction) => transaction.selected)
                        .length
                }
            />
        );
    }

    if (screen === 'partial') {
        return (
            <StepImportPartial
                importedCount={importedCount}
                failures={failures}
                onContinue={onComplete}
                onRetry={startOver}
            />
        );
    }

    return (
        <StepImportFailed
            fileName={rejection?.fileName ?? ''}
            reason={rejection?.reason ?? ''}
            onChooseAnother={startOver}
            onConnectBank={canConnectBank ? onComplete : undefined}
        />
    );
}
