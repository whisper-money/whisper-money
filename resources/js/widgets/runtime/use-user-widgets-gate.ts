import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import {
    markUserWidgetsLoaded,
    markUserWidgetsLoading,
    type SafeModeReason,
    safeModeReason,
    withoutSafeModeParam,
} from './safe-mode';

/**
 * How long the page must keep responding after the last widget drew before
 * the load counts as finished. A widget that hangs in its first effects or
 * right after them stops the timer from ever firing.
 */
export const SETTLE_GRACE_MS = 1500;

export type UserWidgetsGate =
    /** Nothing to mount: the flag is off, or there are no user widgets. */
    | { mode: 'off' }
    /** Not decided yet: the server and the first render cannot know. */
    | { mode: 'pending' }
    | { mode: 'safe'; reason: SafeModeReason; exit: () => void }
    /** Mount them, and call `settle(id)` once each has drawn or failed. */
    | { mode: 'run'; settle: (id: string) => void };

/**
 * Whether the user widgets of a page may mount, and the bookkeeping safe
 * mode needs while they do (see safe-mode.ts). Pass the ids of the user
 * widgets the page is about to draw.
 */
export function useUserWidgetsGate(widgetIds: string[]): UserWidgetsGate {
    const { features } = usePage<SharedData>().props;
    const enabled = Boolean(features?.customDashboards) && widgetIds.length > 0;
    const [decision, setDecision] = useState<
        'pending' | 'run' | SafeModeReason
    >('pending');
    const [settledIds, setSettledIds] = useState<ReadonlySet<string>>(
        () => new Set(),
    );
    const allSettled = widgetIds.every((id) => settledIds.has(id));

    useEffect(() => {
        if (enabled && decision === 'pending') {
            setDecision(safeModeReason(window.location.search) ?? 'run');
        }
    }, [enabled, decision]);

    const running = enabled && decision === 'run';

    // Written before the widgets mount (they render once `running` is
    // committed), cleared a moment after the last one has drawn.
    useEffect(() => {
        if (!running) {
            return;
        }

        if (!allSettled) {
            markUserWidgetsLoading();

            return;
        }

        const timer = setTimeout(markUserWidgetsLoaded, SETTLE_GRACE_MS);

        return () => clearTimeout(timer);
    }, [running, allSettled]);

    // Leaving the page is not a hang: the page was still responding.
    useEffect(() => {
        if (running) {
            return markUserWidgetsLoaded;
        }
    }, [running]);

    const settle = useCallback((id: string) => {
        setSettledIds((previous) =>
            previous.has(id) ? previous : new Set(previous).add(id),
        );
    }, []);

    const exit = useCallback(() => {
        if (decision === 'requested') {
            router.visit(withoutSafeModeParam(window.location.href), {
                preserveScroll: true,
            });

            return;
        }

        markUserWidgetsLoaded();
        setDecision('run');
    }, [decision]);

    if (!enabled) {
        return { mode: 'off' };
    }

    if (decision === 'pending') {
        return { mode: 'pending' };
    }

    if (decision !== 'run') {
        return { mode: 'safe', reason: decision, exit };
    }

    return { mode: 'run', settle };
}
