import { useCallback, useEffect, useRef } from 'react';

/**
 * Self-rescheduling poll loop for background-job status endpoints. The hook owns
 * the timer and tears it down on unmount (or when a new poll starts); the caller
 * provides a `tick` that does the request and returns `'continue'` to keep
 * polling or `'stop'` to finish. A throwing `tick` stops the loop.
 */
export function usePollJobStatus() {
    const timerRef = useRef<ReturnType<typeof setTimeout>>(undefined);
    const activeRef = useRef(false);

    const stop = useCallback(() => {
        activeRef.current = false;
        if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = undefined;
        }
    }, []);

    const start = useCallback(
        (tick: () => Promise<'continue' | 'stop'>, intervalMs = 2000) => {
            stop();
            activeRef.current = true;

            const run = async () => {
                if (!activeRef.current) {
                    return;
                }

                let result: 'continue' | 'stop';
                try {
                    result = await tick();
                } catch {
                    result = 'stop';
                }

                if (!activeRef.current || result === 'stop') {
                    activeRef.current = false;
                    return;
                }

                timerRef.current = setTimeout(run, intervalMs);
            };

            run();
        },
        [stop],
    );

    useEffect(() => stop, [stop]);

    return { start, stop };
}
