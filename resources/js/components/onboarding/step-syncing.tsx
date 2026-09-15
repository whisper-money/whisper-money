import { StepButton } from '@/components/onboarding/step-button';
import { StepScreen } from '@/components/onboarding/step-screen';
import { useLocale } from '@/hooks/use-locale';
import { captureEvent } from '@/lib/posthog';
import { syncStatus } from '@/routes/onboarding';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

// Client-side give-up: the sync keeps running on the queue, but this guarantees
// the spinner resolves even if the worker dies. Beyond the worst case a healthy
// first sync can take (3 attempts of a 120s job, 30s apart).
const MAX_POLL_MS = 5 * 60_000;

const POLL_INTERVAL_MS = 3_000;

/** How the wait ended, from the step's own point of view. */
type SyncOutcome = 'synced' | 'error' | 'stuck' | 'continued_anyway' | 'waited';

interface SyncProgress {
    transactions: number;
    merchants: number;
    accounts: number;
    months: number;
    first_date: string | null;
    last_date: string | null;
}

interface SyncStatusResponse {
    pending: boolean;
    failed: boolean;
    bank: string | null;
    progress: SyncProgress;
}

const NO_PROGRESS: SyncProgress = {
    transactions: 0,
    merchants: 0,
    accounts: 0,
    months: 0,
    first_date: null,
    last_date: null,
};

/**
 * One counter: what it counts on the left, how many on the right.
 *
 * Deliberately not a progress bar. We never learn how much a bank is about to
 * hand over, so a bar would have to invent its own denominator; a number that
 * only goes up promises nothing it cannot keep.
 */
function SyncStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-4">
            <span className="text-[15px] text-muted-foreground">{label}</span>
            <span className="text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </span>
        </div>
    );
}

interface StepSyncingProps {
    onComplete: () => void;
}

export function StepSyncing({ onComplete }: StepSyncingProps) {
    const locale = useLocale();
    const [isPending, setIsPending] = useState<boolean | null>(null);
    const [stalled, setStalled] = useState<'error' | 'stuck' | null>(null);
    const [bank, setBank] = useState<string | null>(null);
    const [progress, setProgress] = useState<SyncProgress>(NO_PROGRESS);
    // Bumped by "wait for the full year", which is the one thing that restarts
    // the poll loop after it has given up.
    const [attempt, setAttempt] = useState(0);
    const onCompleteRef = useRef(onComplete);
    onCompleteRef.current = onComplete;
    const startedAtRef = useRef(0);

    // Every outcome is reported through here, so none of them can drift from
    // the others. The wait is how long the user actually stared at the screen,
    // which is what says whether the 5 minute cap is the right one.
    const reportOutcome = useCallback(
        (status: SyncOutcome) =>
            captureEvent('onboarding_sync_outcome', {
                status,
                waited_seconds: Math.round(
                    (Date.now() - startedAtRef.current) / 1000,
                ),
            }),
        [],
    );

    const advance = useCallback(() => {
        // Always reload transactions so the categorize step sees the latest data,
        // including transactions imported via CSV during onboarding.
        router.reload({
            only: ['transactions'],
            onFinish: () => onCompleteRef.current(),
        });
    }, []);

    // Check sync status immediately on mount, then poll every 3 seconds
    useEffect(() => {
        let cancelled = false;
        let pollTimer: ReturnType<typeof setTimeout>;
        const deadline = Date.now() + MAX_POLL_MS;
        startedAtRef.current = Date.now();

        // Every outcome is reported from inside the `cancelled` guards below, so
        // a StrictMode remount cannot report the same sync twice.
        const stall = (status: 'error' | 'stuck') => {
            reportOutcome(status);
            setIsPending(false);
            setStalled(status);
        };

        // A failing status check says nothing about the sync itself, so both the
        // still-pending and the request-failed paths keep polling until the
        // deadline rather than dropping the user into the next step early.
        const keepPolling = () => {
            if (Date.now() > deadline) {
                stall('stuck');

                return;
            }

            setIsPending(true);
            pollTimer = setTimeout(() => check(), POLL_INTERVAL_MS);
        };

        const check = async () => {
            try {
                const { data } = await axios.get<SyncStatusResponse>(
                    syncStatus().url,
                    { timeout: POLL_INTERVAL_MS },
                );

                if (cancelled) {
                    return;
                }

                setBank(data.bank);
                setProgress(data.progress ?? NO_PROGRESS);

                if (data.failed) {
                    stall('error');
                } else if (data.pending) {
                    keepPolling();
                } else {
                    reportOutcome('synced');
                    setIsPending(false);
                    advance();
                }
            } catch {
                if (!cancelled) {
                    keepPolling();
                }
            }
        };

        check();

        return () => {
            cancelled = true;
            clearTimeout(pollTimer);
        };
    }, [advance, reportOutcome, attempt]);

    /** "Jun – Aug", in the reader's own month names. */
    const covering = useMemo(() => {
        if (!progress.first_date || !progress.last_date) {
            return null;
        }

        const month = new Intl.DateTimeFormat(locale, { month: 'short' });
        const from = month.format(new Date(progress.first_date));
        const to = month.format(new Date(progress.last_date));

        return from === to ? from : `${from} – ${to}`;
    }, [locale, progress.first_date, progress.last_date]);

    // A second event on purpose: the stall already told us the sync stopped,
    // this one tells us how many of those users pressed on instead of leaving.
    const continueAfterStall = () => {
        reportOutcome('continued_anyway');
        advance();
    };

    const keepWaiting = () => {
        reportOutcome('waited');
        setStalled(null);
        setAttempt((current) => current + 1);
    };

    if (stalled === 'error') {
        return (
            <StepScreen
                align="center"
                title={__('We couldn’t finish importing right now')}
                description={__(
                    'Your bank is taking longer than expected. We’ll keep trying in the background and your transactions will show up automatically.',
                )}
                footer={
                    <StepButton
                        text={__('Continue')}
                        onClick={continueAfterStall}
                    />
                }
            />
        );
    }

    if (stalled === 'stuck') {
        return (
            <StepScreen
                title={
                    bank
                        ? __(':bank is taking its time', { bank })
                        : __('Your bank is taking its time')
                }
                description={__(
                    'Nothing is broken. Some banks hand over a year of history in seconds, others take an hour. Yours is one of the slow ones today.',
                )}
                footer={
                    <>
                        <StepButton
                            text={
                                progress.months > 1
                                    ? __('Carry on with :count months', {
                                          count: progress.months,
                                      })
                                    : __('Carry on with what we have')
                            }
                            onClick={continueAfterStall}
                        />
                        <StepButton
                            text={__('Wait for the full year')}
                            variant="ghost"
                            onClick={keepWaiting}
                        />
                    </>
                }
            >
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col divide-y border-t border-b">
                        <SyncStat
                            label={__('Already in')}
                            value={__(':count movements', {
                                count: progress.transactions,
                            })}
                        />
                        {covering && (
                            <SyncStat label={__('Covering')} value={covering} />
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5 rounded-lg bg-muted px-4.5 py-4">
                        <span className="text-sm leading-snug font-medium">
                            {__('You don’t have to sit here.')}
                        </span>
                        <span className="text-sm leading-normal text-pretty text-muted-foreground">
                            {__(
                                'Carry on with what we already have — it is enough to show you the shape of it. The rest lands in the background and appears on its own.',
                            )}
                        </span>
                    </div>
                </div>
            </StepScreen>
        );
    }

    // Don't render anything until we know sync is pending
    if (!isPending) {
        return null;
    }

    return (
        <StepScreen
            align="center"
            title={__('Reading your history')}
            description={
                bank
                    ? __(
                          ':bank is handing over twelve months. This is the slow part — it’s their server, not ours.',
                          { bank },
                      )
                    : __(
                          'Your bank is handing over twelve months. This is the slow part — it’s their server, not ours.',
                      )
            }
        >
            <div className="flex flex-col gap-8">
                <div
                    className="flex flex-col divide-y border-t border-b"
                    aria-live="polite"
                >
                    <SyncStat
                        label={__('Transactions read')}
                        value={progress.transactions.toLocaleString(locale)}
                    />
                    <SyncStat
                        label={__('Months of history')}
                        value={String(progress.months)}
                    />
                    <SyncStat
                        label={__('Places you spent')}
                        value={progress.merchants.toLocaleString(locale)}
                    />
                    <SyncStat
                        label={__('Accounts found')}
                        value={String(progress.accounts)}
                    />
                </div>

                <div className="flex items-center justify-center gap-2.5">
                    <Loader2 className="size-3.5 animate-spin text-muted-foreground" />
                    <span className="text-[13px] text-muted-foreground">
                        {__('Still going. You can leave this screen.')}
                    </span>
                </div>
            </div>
        </StepScreen>
    );
}
