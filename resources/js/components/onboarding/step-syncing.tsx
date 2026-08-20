import { StepButton } from '@/components/onboarding/step-button';
import { StepScreen } from '@/components/onboarding/step-screen';
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

interface StepSyncingProps {
    onComplete: () => void;
}

export function StepSyncing({ onComplete }: StepSyncingProps) {
    const [messageIndex, setMessageIndex] = useState(0);
    const [isPending, setIsPending] = useState<boolean | null>(null);
    const [hasStalled, setHasStalled] = useState(false);
    const onCompleteRef = useRef(onComplete);
    onCompleteRef.current = onComplete;

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

        const stall = () => {
            setIsPending(false);
            setHasStalled(true);
        };

        // A failing status check says nothing about the sync itself, so both the
        // still-pending and the request-failed paths keep polling until the
        // deadline rather than dropping the user into the next step early.
        const keepPolling = () => {
            if (Date.now() > deadline) {
                stall();

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
                    stall();
                } else if (data.pending) {
                    keepPolling();
                } else {
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
    }, [advance]);

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

    if (hasStalled) {
        return (
            <StepScreen
                align="center"
                title={__('We couldn’t finish importing right now')}
                description={__(
                    'Your bank is taking longer than expected. We’ll keep trying in the background and your transactions will show up automatically.',
                )}
                footer={<StepButton text={__('Continue')} onClick={advance} />}
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
