import { StepButton } from '@/components/onboarding/step-button';
import { StepScreen } from '@/components/onboarding/step-screen';
import { captureEvent } from '@/lib/posthog';
import { syncStatus } from '@/routes/onboarding';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// Client-side give-up: the sync keeps running on the queue, but this guarantees
// the spinner resolves even if the worker dies. Beyond the worst case a healthy
// first sync can take (3 attempts of a 120s job, 30s apart).
const MAX_POLL_MS = 5 * 60_000;

const POLL_INTERVAL_MS = 3_000;

const MESSAGES = [
    'Importing your balances...',
    'Fetching your transactions...',
    'Teaching the robots to count...',
    'Making your money talk...',
    'Crunching the numbers...',
    'Patience is a virtue (especially with money)...',
    'Counting every penny...',
    'Connecting the dots in your finances...',
    'Almost there, just double-checking the math...',
] as const;

/** How the wait ended, from the step's own point of view. */
type SyncOutcome = 'synced' | 'error' | 'stuck' | 'continued_anyway';

interface StepSyncingProps {
    onComplete: () => void;
}

export function StepSyncing({ onComplete }: StepSyncingProps) {
    const [messageIndex, setMessageIndex] = useState(0);
    const [isPending, setIsPending] = useState<boolean | null>(null);
    const [hasStalled, setHasStalled] = useState(false);
    const onCompleteRef = useRef(onComplete);
    onCompleteRef.current = onComplete;
    const startedAtRef = useRef(0);

    // Every outcome is reported through here, so none of the four can drift from
    // the others. The wait is how long the user actually stared at the spinner,
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
            setHasStalled(true);
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
                const { data } = await axios.get<{
                    pending: boolean;
                    failed: boolean;
                }>(syncStatus().url, { timeout: POLL_INTERVAL_MS });

                if (cancelled) {
                    return;
                }

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
    }, [advance, reportOutcome]);

    // Rotate through messages every 2 seconds while pending
    useEffect(() => {
        if (!isPending) {
            return;
        }

        const interval = setInterval(() => {
            setMessageIndex((i) => (i + 1) % MESSAGES.length);
        }, 2000);

        return () => clearInterval(interval);
    }, [isPending]);

    // A second event on purpose: the stall already told us the sync broke, this
    // one tells us how many of those users pressed on instead of leaving.
    const continueAfterStall = () => {
        reportOutcome('continued_anyway');
        advance();
    };

    if (hasStalled) {
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

    // Don't render anything until we know sync is pending
    if (!isPending) {
        return null;
    }

    return (
        <StepScreen align="center">
            <div className="flex flex-col items-center gap-6 text-center">
                <Loader2 className="size-7 animate-spin" />

                <div className="flex flex-col gap-2">
                    <h1
                        className="text-[17px] font-medium transition-all duration-500"
                        aria-live="polite"
                    >
                        {__(MESSAGES[messageIndex])}
                    </h1>
                    <p className="text-[15px] text-muted-foreground">
                        {__('This will only take a moment.')}
                    </p>
                </div>
            </div>
        </StepScreen>
    );
}
