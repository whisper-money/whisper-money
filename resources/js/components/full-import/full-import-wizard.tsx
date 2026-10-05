import {
    Notice,
    WizardFooter,
    WizardHeader,
    type WizardPill,
    type WizardVariant,
} from '@/components/full-import/full-import-layout';
import { StepAccounts } from '@/components/full-import/step-accounts';
import { StepCategories } from '@/components/full-import/step-categories';
import { StepColumns } from '@/components/full-import/step-columns';
import { StepDone } from '@/components/full-import/step-done';
import { StepExisting } from '@/components/full-import/step-existing';
import { StepFile } from '@/components/full-import/step-file';
import {
    StepProgress,
    type UploadProgress,
} from '@/components/full-import/step-progress';
import { StepReview } from '@/components/full-import/step-review';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/hooks/use-locale';
import {
    fetchBankMatches,
    fetchImport,
    fetchImportContext,
    POLL_INTERVAL_MS,
    requestErrorMessage,
    submitImport,
} from '@/lib/full-import-api';
import { formatCount, sourceSuffix } from '@/lib/full-import-format';
import {
    buildImport,
    categoryNodeId,
    detectAccounts,
    detectCategories,
    importedRows,
    isOwnTransferNode,
    resolveAccountPlan,
    resolveCategoryPlan,
} from '@/lib/full-import-plan';
import {
    applySavedProfile,
    defaultMapping,
    detectSource,
    normalizeRows,
    readFullImportFile,
} from '@/lib/full-import-profiles';
import {
    isSupportedImportFile,
    MAX_IMPORT_FILE_BYTES,
    unsupportedFileReason,
    type ParsedImportFile,
} from '@/lib/transaction-import';
import { type SharedData } from '@/types';
import {
    SINGLE_ACCOUNT_COLUMN,
    type AccountPlanEntry,
    type BankLite,
    type CategoryPlanEntry,
    type FullImportContext,
    type FullImportMapping,
    type FullImportMode,
    type FullImportSource,
    type ImportStatus,
    type NormalizedFile,
} from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

type PlanningStep =
    | 'file'
    | 'existing'
    | 'columns'
    | 'accounts'
    | 'categories'
    | 'review';

type Step = PlanningStep | 'progress' | 'done';

const STEP_LABELS: Record<PlanningStep, string> = {
    file: 'File',
    existing: 'Your data',
    columns: 'Columns',
    accounts: 'Accounts',
    categories: 'Categories',
    review: 'Review',
};

const EMPTY_FILE: NormalizedFile = { rows: [], unreadable: [], blankRows: 0 };

/** Polls stop being worth it once the AI pass is all that is left to watch. */
const AI_POLL_INTERVAL_MS = 4000;

interface FullImportWizardProps {
    /** Its own page in Settings, or inside the onboarding's accounts step. */
    variant: WizardVariant;
    onClose: () => void;
    /** Leaving the done screen inside the onboarding. */
    onFinished?: () => void;
}

/**
 * The full import from another app: read the file in the browser, let the
 * user check the columns, accounts and categories it found, then hand the
 * plan and the rows to the server, which writes them in a queued job.
 *
 * Every plan the screens show is derived from the file and the user's
 * choices (the overrides), never stored: changing a column re-reads the rows,
 * and the accounts and categories follow without any of it going stale.
 */
export function FullImportWizard({
    variant,
    onClose,
    onFinished,
}: FullImportWizardProps) {
    const { auth, currencies } = usePage<SharedData>().props;
    const locale = useLocale();

    const [context, setContext] = useState<FullImportContext | null>(null);
    const [contextFailed, setContextFailed] = useState(false);
    const [step, setStep] = useState<Step>('file');

    const [source, setSource] = useState<FullImportSource>('banktrack');
    const [parsed, setParsed] = useState<ParsedImportFile | null>(null);
    const [recognized, setRecognized] = useState(false);
    const [fileError, setFileError] = useState<string | null>(null);
    const [parsing, setParsing] = useState(false);
    const [mapping, setMapping] = useState<FullImportMapping | null>(null);

    const [mode, setMode] = useState<FullImportMode>('add');
    const [accountOverrides, setAccountOverrides] = useState<
        Record<string, AccountPlanEntry>
    >({});
    const [banks, setBanks] = useState<Record<string, BankLite | null>>({});
    const [categoryOverrides, setCategoryOverrides] = useState<
        Record<string, Partial<CategoryPlanEntry>>
    >({});
    const [ownChoice, setOwnChoice] = useState<string | null | undefined>();
    const [ignoredChoice, setIgnoredChoice] = useState<
        string | null | undefined
    >();
    const [confirmWipe, setConfirmWipe] = useState(false);

    const [upload, setUpload] = useState<UploadProgress | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [status, setStatus] = useState<ImportStatus | null>(null);

    const loadContext = useCallback(() => {
        setContextFailed(false);

        fetchImportContext()
            .then(async (loaded) => {
                setContext(loaded);

                // An import already running is all this screen can show: a
                // second one would be refused until it finishes.
                if (loaded.running) {
                    setStatus(await fetchImport(loaded.running));
                    setStep('progress');
                }
            })
            .catch(() => setContextFailed(true));
    }, []);

    useEffect(() => {
        loadContext();
    }, [loadContext]);

    // Poll the job while it runs, then a little longer while the AI pass
    // after it does, so the done screen can say when it finished.
    useEffect(() => {
        if (!status || status.status === 'draft') {
            return;
        }

        const finished =
            status.status === 'completed' || status.status === 'failed';
        const aiRunning =
            status.stats.ai?.status === 'queued' ||
            status.stats.ai?.status === 'running';

        if (finished && step === 'progress') {
            setStep('done');

            return;
        }

        if (finished && !aiRunning) {
            return;
        }

        const timer = window.setTimeout(
            () => {
                fetchImport(status.id)
                    .then(setStatus)
                    .catch(() => setStatus({ ...status }));
            },
            finished ? AI_POLL_INTERVAL_MS : POLL_INTERVAL_MS,
        );

        return () => window.clearTimeout(timer);
    }, [status, step]);

    const supportedCurrencies = useMemo(
        () => currencies.accounts.map((currency) => currency.code),
        [currencies],
    );

    const normalized = useMemo(
        () =>
            parsed && mapping
                ? normalizeRows(parsed, mapping, {
                      fileName: parsed.file.name,
                      supportedCurrencies,
                  })
                : EMPTY_FILE,
        [parsed, mapping, supportedCurrencies],
    );

    const fileAccounts = useMemo(
        () => detectAccounts(normalized.rows),
        [normalized],
    );

    const accountContext = useMemo(
        () => ({
            mode,
            accounts: context?.accounts ?? [],
            mappableAccountIds: context?.mappableAccountIds ?? [],
            userCurrency: auth.user.currency_code,
            supportedCurrencies,
            sourceLabel: sourceSuffix(source),
            banks,
        }),
        [
            mode,
            context,
            auth.user.currency_code,
            supportedCurrencies,
            source,
            banks,
        ],
    );

    const accountPlan = useMemo(
        () =>
            resolveAccountPlan(fileAccounts, accountOverrides, accountContext),
        [fileAccounts, accountOverrides, accountContext],
    );

    const rowsToImport = useMemo(
        () => importedRows(normalized.rows, accountPlan),
        [normalized, accountPlan],
    );
    const categorizedRows = useMemo(
        () => rowsToImport.filter((row) => !row.ignored),
        [rowsToImport],
    );
    const nodes = useMemo(
        () => detectCategories(categorizedRows),
        [categorizedRows],
    );
    const categoryPlan = useMemo(
        () =>
            resolveCategoryPlan(nodes, categoryOverrides, {
                categories: context?.categories ?? [],
                defaultNames: context?.defaultCategoryNames ?? [],
            }),
        [nodes, categoryOverrides, context],
    );

    const counts = useMemo(() => {
        const ownIds = new Set(
            nodes
                .filter((node) => isOwnTransferNode(node, nodes))
                .map((node) => node.id),
        );

        return {
            own: categorizedRows.filter(
                (row) =>
                    row.categoryPath.length > 0 &&
                    ownIds.has(categoryNodeId(row.categoryPath)),
            ).length,
            ignored: rowsToImport.length - categorizedRows.length,
            uncategorized: categorizedRows.filter(
                (row) => row.categoryPath.length === 0,
            ).length,
            skippedAccounts: normalized.rows.length - rowsToImport.length,
        };
    }, [nodes, categorizedRows, rowsToImport, normalized]);

    const transfers = useMemo(() => {
        const targets = context?.transferTargets;

        return targets
            ? {
                  own: {
                      categoryId:
                          ownChoice === undefined
                              ? targets.own.category_id
                              : ownChoice,
                      target: targets.own,
                  },
                  ignored: {
                      categoryId:
                          ignoredChoice === undefined
                              ? targets.ignored.category_id
                              : ignoredChoice,
                      target: targets.ignored,
                  },
              }
            : null;
    }, [context, ownChoice, ignoredChoice]);

    const built = useMemo(
        () =>
            step === 'review' && mapping && transfers && context
                ? buildImport({
                      source,
                      fileName: parsed?.file.name ?? null,
                      mode,
                      mapping,
                      rows: normalized.rows,
                      fileAccounts,
                      accountPlan,
                      contextAccounts: context.accounts,
                      nodes,
                      categoryPlan,
                      categories: context.categories,
                      transfers,
                  })
                : null,
        [
            step,
            mapping,
            transfers,
            context,
            source,
            parsed,
            mode,
            normalized,
            fileAccounts,
            accountPlan,
            nodes,
            categoryPlan,
        ],
    );

    // The bank behind each account name is a server question; asked once per
    // name, when the accounts step first needs it.
    useEffect(() => {
        if (step !== 'accounts') {
            return;
        }

        const missing = fileAccounts
            .map((account) => account.name)
            .filter((name) => !(name in banks));

        if (missing.length === 0) {
            return;
        }

        let active = true;

        fetchBankMatches(missing)
            .catch(() => ({}))
            .then((matches: Record<string, BankLite | null>) => {
                if (active) {
                    setBanks((previous) => ({
                        ...previous,
                        ...Object.fromEntries(
                            missing.map((name) => [
                                name,
                                matches[name] ?? null,
                            ]),
                        ),
                    }));
                }
            });

        return () => {
            active = false;
        };
    }, [step, fileAccounts, banks]);

    const mappingFor = useCallback(
        (nextSource: FullImportSource, file: ParsedImportFile) =>
            applySavedProfile(
                defaultMapping(nextSource, file, locale),
                context?.profiles[nextSource] ?? null,
                file.headers,
            ),
        [context, locale],
    );

    const resetPlans = () => {
        setAccountOverrides({});
        setCategoryOverrides({});
        setOwnChoice(undefined);
        setIgnoredChoice(undefined);
    };

    const handleFile = async (file: File) => {
        setFileError(null);

        if (!isSupportedImportFile(file)) {
            setFileError(unsupportedFileReason(file.name));
            return;
        }

        if (file.size > MAX_IMPORT_FILE_BYTES) {
            setFileError(__('That file is over 10 MB'));
            return;
        }

        setParsing(true);

        try {
            const read = await readFullImportFile(file, locale);

            if (read.rows.length === 0) {
                setFileError(__('There are no rows in it'));
                return;
            }

            const detected = detectSource(read.headers);
            const nextSource = detected ?? 'generic';

            setParsed(read);
            setRecognized(detected === 'banktrack');
            setSource(nextSource);
            setMapping(mappingFor(nextSource, read));
            resetPlans();
        } catch (error) {
            setFileError(
                __(
                    error instanceof Error
                        ? error.message
                        : 'Failed to parse file',
                ),
            );
        } finally {
            setParsing(false);
        }
    };

    const handleSourceChange = (nextSource: FullImportSource) => {
        setSource(nextSource);

        if (parsed) {
            setMapping(mappingFor(nextSource, parsed));
            resetPlans();
        }
    };

    const handleSubmit = async () => {
        if (!built) {
            return;
        }

        setSubmitError(null);
        setUpload({ sent: 0, total: built.transactions.length });
        setStep('progress');

        try {
            setStatus(
                await submitImport(
                    built.payload,
                    built.transactions,
                    built.balances,
                    (sent, total) => setUpload({ sent, total }),
                ),
            );
        } catch (error) {
            setSubmitError(
                requestErrorMessage(
                    error,
                    __('The import could not be sent. Try again.'),
                ),
            );
            setUpload(null);
            setStep('review');
        }
    };

    const hasExisting = (context?.accounts.length ?? 0) > 0;
    const steps = useMemo(
        (): PlanningStep[] => [
            'file',
            ...(hasExisting ? (['existing'] as const) : []),
            'columns',
            'accounts',
            'categories',
            'review',
        ],
        [hasExisting],
    );
    const stepIndex = steps.indexOf(step as PlanningStep);
    const pills: WizardPill[] =
        stepIndex === -1
            ? []
            : steps.map((id) => ({ id, label: __(STEP_LABELS[id]) }));

    const goBack = () =>
        stepIndex <= 0 ? onClose() : setStep(steps[stepIndex - 1]);
    const goNext = () => setStep(steps[stepIndex + 1]);

    const mapped = (column: string | null) => column !== null && column !== '';
    const canContinue: Record<PlanningStep, boolean> = {
        file: parsed !== null && !parsing && fileError === null,
        existing: true,
        columns:
            mapping !== null &&
            mapped(mapping.date) &&
            mapped(mapping.amount) &&
            mapped(mapping.description) &&
            (mapped(mapping.account) ||
                mapping.account === SINGLE_ACCOUNT_COLUMN) &&
            normalized.rows.length > 0,
        accounts: Object.values(accountPlan).some(
            (entry) => entry.action === 'create' || entry.action === 'map',
        ),
        categories: true,
        review:
            built !== null &&
            built.transactions.length > 0 &&
            (mode !== 'wipe' || confirmWipe),
    };

    const footer = (primary?: ReactNode, note?: ReactNode) => (
        <WizardFooter
            onBack={goBack}
            backLabel={stepIndex === 0 ? __('Cancel') : undefined}
            note={note}
            primary={
                primary ?? (
                    <Button
                        disabled={!canContinue[step as PlanningStep]}
                        onClick={goNext}
                    >
                        {__('Continue')}
                    </Button>
                )
            }
        />
    );

    const eyebrow = __('Step :current of :total', {
        current: stepIndex + 1,
        total: steps.length,
    });

    const aiReviewNote = context?.aiAvailable
        ? __(
              'Afterwards, the AI will categorize the :count transactions that arrive without a category.',
              { count: formatCount(counts.uncategorized, locale) },
          )
        : __(
              ':count transactions arrive without a category and stay that way. With a paid plan, the AI categorizes them for you.',
              { count: formatCount(counts.uncategorized, locale) },
          );

    const renderStep = (): ReactNode => {
        if (!context || !transfers) {
            return contextFailed ? (
                <Notice tone="danger">
                    <span>{__('We could not load your data. Try again.')}</span>
                    <Button
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        onClick={loadContext}
                    >
                        {__('Retry')}
                    </Button>
                </Notice>
            ) : (
                <div className="flex justify-center py-16">
                    <Spinner className="size-6" />
                </div>
            );
        }

        switch (step) {
            case 'file':
                return (
                    <StepFile
                        eyebrow={eyebrow}
                        source={source}
                        onSourceChange={handleSourceChange}
                        file={parsed?.file ?? null}
                        summary={
                            parsed
                                ? {
                                      rows: normalized.rows.length,
                                      from:
                                          normalized.rows
                                              .map((row) => row.date)
                                              .sort()[0] ?? null,
                                      to:
                                          normalized.rows
                                              .map((row) => row.date)
                                              .sort()
                                              .at(-1) ?? null,
                                  }
                                : null
                        }
                        recognized={recognized && source === 'banktrack'}
                        error={fileError}
                        parsing={parsing}
                        locale={locale}
                        onFileSelect={handleFile}
                        footer={footer()}
                    />
                );
            case 'existing':
                return (
                    <StepExisting
                        eyebrow={eyebrow}
                        accounts={context.accounts}
                        mode={mode}
                        onModeChange={(next) => {
                            setMode(next);
                            setConfirmWipe(false);
                        }}
                        locale={locale}
                        footer={footer()}
                    />
                );
            case 'columns':
                return parsed && mapping ? (
                    <StepColumns
                        eyebrow={eyebrow}
                        source={source}
                        onSourceChange={handleSourceChange}
                        headers={parsed.headers}
                        columnOptions={parsed.columnOptions}
                        mapping={mapping}
                        onMappingChange={setMapping}
                        normalized={normalized}
                        fallbackCurrency={auth.user.currency_code}
                        locale={locale}
                        footer={footer()}
                    />
                ) : null;
            case 'accounts':
                return (
                    <StepAccounts
                        eyebrow={eyebrow}
                        fileAccounts={fileAccounts}
                        plan={accountPlan}
                        onChange={(key, entry) =>
                            setAccountOverrides((previous) => ({
                                ...previous,
                                [key]: entry,
                            }))
                        }
                        mode={mode}
                        accounts={context.accounts}
                        mappableAccountIds={context.mappableAccountIds}
                        currencies={currencies.accounts}
                        sourceLabel={sourceSuffix(source)}
                        banksVersion={String(Object.keys(banks).length)}
                        locale={locale}
                        footer={footer()}
                    />
                );
            case 'categories':
                return (
                    <StepCategories
                        eyebrow={eyebrow}
                        nodes={nodes}
                        plan={categoryPlan}
                        onChange={(nodeId, entry) =>
                            setCategoryOverrides((previous) => ({
                                ...previous,
                                [nodeId]: { ...previous[nodeId], ...entry },
                            }))
                        }
                        categories={context.categories}
                        own={{
                            count: counts.own,
                            ...transfers.own,
                            onChange: setOwnChoice,
                        }}
                        ignored={{
                            count: counts.ignored,
                            ...transfers.ignored,
                            onChange: setIgnoredChoice,
                        }}
                        uncategorized={counts.uncategorized}
                        aiAvailable={context.aiAvailable}
                        locale={locale}
                        footer={footer()}
                    />
                );
            case 'review':
                return built ? (
                    <div className="flex flex-col gap-4">
                        {submitError && (
                            <Notice tone="danger">{submitError}</Notice>
                        )}
                        <StepReview
                            eyebrow={eyebrow}
                            mode={mode}
                            fileAccounts={fileAccounts}
                            plan={accountPlan}
                            accounts={context.accounts}
                            built={built}
                            unreadable={normalized.unreadable.length}
                            uncategorized={counts.uncategorized}
                            aiNote={aiReviewNote}
                            confirmWipe={confirmWipe}
                            onConfirmWipeChange={setConfirmWipe}
                            locale={locale}
                            footer={footer(
                                <Button
                                    disabled={!canContinue.review}
                                    onClick={handleSubmit}
                                >
                                    {__('Import :count transactions', {
                                        count: formatCount(
                                            built.transactions.length,
                                            locale,
                                        ),
                                    })}
                                </Button>,
                                mode === 'wipe'
                                    ? __(
                                          'Undoing the import does not bring back what is deleted',
                                      )
                                    : __('You can undo it from Settings'),
                            )}
                        />
                    </div>
                ) : null;
            case 'progress':
                return (
                    <StepProgress
                        status={status}
                        upload={upload}
                        showDashboardLink={variant === 'page'}
                        locale={locale}
                    />
                );
            case 'done':
                return status ? (
                    <StepDone
                        status={status}
                        variant={variant}
                        clientSkipped={[
                            {
                                label: __('From accounts you did not import'),
                                count: counts.skippedAccounts,
                            },
                            {
                                label: __("Rows that can't be read"),
                                count: normalized.unreadable.length,
                            },
                        ]}
                        onFinished={onFinished ?? onClose}
                        locale={locale}
                    />
                ) : null;
        }
    };

    if (variant === 'embedded') {
        return (
            <main className="flex flex-1 flex-col px-5 md:items-center md:px-7">
                <div className="flex w-full max-w-[800px] flex-col gap-8 py-6 md:py-14">
                    <WizardHeader
                        variant="embedded"
                        steps={pills}
                        current={step}
                        onClose={onClose}
                    />
                    {renderStep()}
                </div>
            </main>
        );
    }

    return (
        <div className="min-h-screen bg-background text-foreground">
            <WizardHeader
                variant="page"
                steps={pills}
                current={step}
                onClose={onClose}
            />
            <main className="flex justify-center px-4 py-10 sm:px-6 md:py-12">
                <div className="w-full max-w-[800px]">{renderStep()}</div>
            </main>
        </div>
    );
}
