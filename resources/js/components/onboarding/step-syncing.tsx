import { syncStatus } from '@/routes/onboarding';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

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

        const check = async () => {
            try {
                const { data } = await axios.get<{ pending: boolean }>(
                    syncStatus().url,
                );

                if (cancelled) {
                    return;
                }

                if (!data.pending) {
                    setIsPending(false);
                    advance();
                } else {
                    setIsPending(true);
                    pollTimer = setTimeout(() => check(), 3000);
                }
            } catch {
                if (!cancelled) {
                    // On error, advance anyway to not block the user
                    advance();
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

    // Don't render anything until we know sync is pending
    if (!isPending) {
        return null;
    }

    return (
        <div className="flex w-full flex-col items-center gap-8 py-8 text-center">
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/30">
                <Loader2 className="h-10 w-10 animate-spin text-violet-600 dark:text-violet-400" />
            </div>

            <div className="space-y-2">
                <p className="text-base font-medium text-foreground transition-all duration-500">
                    {__(MESSAGES[messageIndex])}
                </p>
                <p className="text-sm text-muted-foreground">
                    {__('This will only take a moment.')}
                </p>
            </div>
        </div>
    );
}
