import { index as importIndex } from '@/actions/App/Http/Controllers/Settings/FullImportController';
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
import { useDuplicateEstimate } from '@/hooks/use-duplicate-estimate';
import { useFullImportPlan } from '@/hooks/use-full-import-plan';
import {
    useImportPolling,
    type PollingProblem,
} from '@/hooks/use-import-polling';
import { useLocale } from '@/hooks/use-locale';
import {
    fetchImport,
    fetchImportContext,
    requestErrorMessage,
    submitImport,
} from '@/lib/full-import-api';
import {
    countLabel,
    formatCount,
    sourceSuffix,
} from '@/lib/full-import-format';
import {
    applySavedProfile,
    defaultMapping,
    detectSource,
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
    type FullImportContext,
    type FullImportMapping,
    type FullImportMode,
    type FullImportSource,
} from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
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

/** The progress screen turns into the done screen by itself once the job ends. */
type Step = PlanningStep | 'progress';

const STEP_LABELS: Record<PlanningStep, string> = {
    file: 'File',
    existing: 'Your data',
    columns: 'Columns',
    accounts: 'Accounts',
    categories: 'Categories',
    review: 'Review',
};

interface FullImportWizardProps {
    /** Its own page in Settings, or inside the onboarding's accounts step. */
    variant: WizardVariant;
    onClose: () => void;
    /** Leaving the done screen inside the onboarding. */
    onFinished?: () => void;
}

/**
 * While the rows are still going up, leaving the tab would strand the import
 * half-sent. Once `start` has answered, the job has everything and the user
 * may close the tab, so the guard goes with it.
 */
function useLeaveGuard(active: boolean): void {
    useEffect(() => {
        if (!active) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            // Still what Chromium and Safari read to show their prompt.
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [active]);
}

/** What the screen says when it can no longer follow the import. */
function PollingProblemNotice({
    problem,
    variant,
    onRetry,
}: {
    problem: Exclude<PollingProblem, null>;
    variant: WizardVariant;
    onRetry: () => void;
}) {
    return (
        <Notice tone="danger">
            <span>
                {problem === 'gone'
                    ? __('This import is no longer available.')
                    : __(
                          "We can't reach the server to follow the import. It keeps running on our side.",
                      )}
            </span>
            <span className="flex flex-wrap items-center gap-3">
                {problem === 'unreachable' && (
                    <Button variant="outline" size="sm" onClick={onRetry}>
                        {__('Retry')}
                    </Button>
                )}
                {variant === 'page' && (
                    <Link
                        href={importIndex()}
                        className="text-sm font-medium underline underline-offset-4"
                    >
                        {__('Go to Settings')}
                    </Link>
                )}
            </span>
        </Notice>
    );
}

/** "Import 36 transactions", or plain "Import" when none look new. */
function importButtonLabel(count: number, locale: string): string {
    if (count === 0) {
        return __('Import');
    }

    return countLabel(
        count,
        __('Import 1 transaction'),
        __('Import :count transactions', {
            count: formatCount(count, locale),
        }),
    );
}

/**
 * The full import from another app: read the file in the browser, let the
 * user check the columns, accounts and categories it found, then hand the
 * plan and the rows to the server, which writes them in a queued job.
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
    const [confirmWipe, setConfirmWipe] = useState(false);

    const [upload, setUpload] = useState<UploadProgress | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const { status, setStatus, problem, retry } = useImportPolling();

    const plan = useFullImportPlan({
        parsed,
        mapping,
        context,
        source,
        mode,
        lookUpBanks: step === 'accounts',
        withPayload: step === 'review',
    });
    const { normalized, built, counts } = plan;

    const duplicates = useDuplicateEstimate(built, plan.existingTargets);
    // The estimate only ever lowers the count: the server dedupes again,
    // by the file's own ids too, while it writes.
    const likelyNew = built
        ? built.transactions.length - (duplicates.estimate?.existing ?? 0)
        : 0;

    useLeaveGuard(step === 'progress' && upload !== null && status === null);

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
    }, [setStatus]);

    useEffect(() => {
        loadContext();
    }, [loadContext]);

    const mappingFor = useCallback(
        (nextSource: FullImportSource, file: ParsedImportFile) =>
            applySavedProfile(
                defaultMapping(nextSource, file, locale),
                context?.profiles[nextSource] ?? null,
                file.headers,
            ),
        [context, locale],
    );

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
            plan.reset();
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
            plan.reset();
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
    const finished =
        status?.status === 'completed' || status?.status === 'failed';

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
        accounts: Object.values(plan.accountPlan).some(
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

    const uncategorized = formatCount(counts.uncategorized, locale);
    const aiReviewNote = context?.aiAvailable
        ? countLabel(
              counts.uncategorized,
              __(
                  'Afterwards, the AI will categorize the transaction that arrives without a category.',
              ),
              __(
                  'Afterwards, the AI will categorize the :count transactions that arrive without a category.',
                  { count: uncategorized },
              ),
          )
        : countLabel(
              counts.uncategorized,
              __(
                  '1 transaction arrives without a category and stays that way. With a paid plan, the AI categorizes it for you.',
              ),
              __(
                  ':count transactions arrive without a category and stay that way. With a paid plan, the AI categorizes them for you.',
                  { count: uncategorized },
              ),
          );

    const renderStep = (): ReactNode => {
        if (!context || !plan.transfers) {
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
                        fileAccounts={plan.fileAccounts}
                        plan={plan.accountPlan}
                        onChange={plan.setAccount}
                        mode={mode}
                        accounts={context.accounts}
                        mappableAccountIds={context.mappableAccountIds}
                        currencies={currencies.accounts}
                        sourceLabel={sourceSuffix(source)}
                        banksVersion={plan.banksVersion}
                        locale={locale}
                        footer={footer()}
                    />
                );
            case 'categories':
                return (
                    <StepCategories
                        eyebrow={eyebrow}
                        nodes={plan.nodes}
                        plan={plan.categoryPlan}
                        onChange={plan.setCategory}
                        categories={context.categories}
                        own={{
                            count: counts.own,
                            ...plan.transfers.own,
                            onChange: plan.setOwnChoice,
                        }}
                        ignored={{
                            count: counts.ignored,
                            ...plan.transfers.ignored,
                            onChange: plan.setIgnoredChoice,
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
                            fileAccounts={plan.fileAccounts}
                            plan={plan.accountPlan}
                            accounts={context.accounts}
                            built={built}
                            unreadable={normalized.unreadable}
                            uncategorized={counts.uncategorized}
                            aiNote={aiReviewNote}
                            confirmWipe={confirmWipe}
                            onConfirmWipeChange={setConfirmWipe}
                            locale={locale}
                            estimate={duplicates.estimate}
                            checkingDuplicates={duplicates.checking}
                            footer={footer(
                                <Button
                                    disabled={!canContinue.review}
                                    onClick={handleSubmit}
                                >
                                    {importButtonLabel(likelyNew, locale)}
                                </Button>,
                                likelyNew === 0
                                    ? __(
                                          'Likely nothing new to import: it all seems to be there already.',
                                      )
                                    : mode === 'wipe'
                                      ? __(
                                            'Undoing the import does not bring back what is deleted',
                                        )
                                      : __('You can undo it from Settings'),
                            )}
                        />
                    </div>
                ) : null;
            default:
                return status && finished ? (
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
                ) : (
                    <StepProgress
                        status={status}
                        upload={upload}
                        showDashboardLink={variant === 'page'}
                        locale={locale}
                    />
                );
        }
    };

    const content = (
        <>
            {problem && step === 'progress' && (
                <PollingProblemNotice
                    problem={problem}
                    variant={variant}
                    onRetry={retry}
                />
            )}
            {renderStep()}
        </>
    );

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
                    {content}
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
                <div className="flex w-full max-w-[800px] flex-col gap-6">
                    {content}
                </div>
            </main>
        </div>
    );
}
