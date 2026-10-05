import { fetchImport, POLL_INTERVAL_MS } from '@/lib/full-import-api';
import { type ImportStatus } from '@/types/full-import';
import { isAxiosError } from 'axios';
import { useCallback, useEffect, useState } from 'react';

/** Once only the AI pass after the import is left to watch, there is no hurry. */
export const AI_POLL_INTERVAL_MS = 4000;

/** The longest wait between two tries while the server is not answering. */
export const MAX_BACKOFF_MS = 15_000;

/** Failures in a row before polling stops and the user is asked instead. */
export const MAX_POLL_FAILURES = 5;

/**
 * Why polling stopped short of the end: the server stopped answering, or it
 * answered that the import is not there (any more) for this user.
 */
export type PollingProblem = 'unreachable' | 'gone' | null;

function isFinished(status: ImportStatus): boolean {
    return status.status === 'completed' || status.status === 'failed';
}

function isAiRunning(status: ImportStatus): boolean {
    return (
        status.stats.ai?.status === 'queued' ||
        status.stats.ai?.status === 'running'
    );
}

/**
 * Whether asking again can still tell the user something: while the job
 * runs, and after it while the single AI pass it queued does.
 */
export function needsPolling(
    status: ImportStatus | null,
): status is ImportStatus {
    if (!status || status.status === 'draft') {
        return false;
    }

    return !isFinished(status) || isAiRunning(status);
}

/**
 * The wait before the next ask: steady while answers come, doubling up to a
 * ceiling while they do not, so a server having a bad minute is not hammered.
 */
export function pollDelay(status: ImportStatus, failures: number): number {
    if (failures === 0) {
        return isFinished(status) ? AI_POLL_INTERVAL_MS : POLL_INTERVAL_MS;
    }

    return Math.min(MAX_BACKOFF_MS, POLL_INTERVAL_MS * 2 ** failures);
}

/**
 * Follows an import on the server until there is nothing left to follow.
 * Set the status to start (the one `start` returned, or a running import's);
 * every answer replaces it.
 */
export function useImportPolling() {
    const [status, setStatus] = useState<ImportStatus | null>(null);
    const [failures, setFailures] = useState(0);
    const [gone, setGone] = useState(false);
    /** The latest failure, so whoever shows the give-up can also report it. */
    const [lastError, setLastError] = useState<unknown>(null);
    const unreachable = failures >= MAX_POLL_FAILURES;

    useEffect(() => {
        if (gone || unreachable || !needsPolling(status)) {
            return;
        }

        let active = true;
        const timer = window.setTimeout(
            () => {
                fetchImport(status.id)
                    .then((next) => {
                        if (active) {
                            setFailures(0);
                            setStatus(next);
                        }
                    })
                    .catch((error: unknown) => {
                        if (!active) {
                            return;
                        }

                        const code = isAxiosError(error)
                            ? error.response?.status
                            : undefined;

                        // Not this user's import, or no longer there: asking
                        // again cannot change the answer.
                        if (code === 403 || code === 404) {
                            setGone(true);

                            return;
                        }

                        setLastError(error);
                        setFailures((count) => count + 1);
                    });
            },
            pollDelay(status, failures),
        );

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [status, failures, gone, unreachable]);

    const retry = useCallback(() => setFailures(0), []);

    const problem: PollingProblem = gone
        ? 'gone'
        : unreachable
          ? 'unreachable'
          : null;

    return { status, setStatus, problem, retry, lastError };
}
