import { captureEvent } from '@/lib/posthog';
import { getStorage } from '@/lib/safe-storage';
import { type User } from '@/types';
import { type SetupChecklist } from '@/types/dashboard';
import { useEffect, useRef } from 'react';

/**
 * Set while this browser has shown the "Finish setting up" card with steps
 * left, so that the visit on which the server stops sending it can tell why.
 */
const PENDING_KEY = 'dashboard-setup-card-pending';

/** The card's window after onboarding: SetupChecklist::WINDOW_DAYS on the server. */
const WINDOW_DAYS = 14;

const DAY_MS = 24 * 60 * 60 * 1000;

export type SetupCardProgress = {
    steps_total: number;
    steps_done: number;
};

/** How far the card's steps are, as every setup event reports it. */
export function setupCardProgress(
    checklist: SetupChecklist,
): SetupCardProgress {
    return {
        steps_total: checklist.steps.length,
        steps_done: checklist.steps.filter((step) => step.done).length,
    };
}

/** Once per page load: the card was drawn, and how far along it was. */
export function useSetupCardViewedEvent(checklist: SetupChecklist): void {
    const sent = useRef(false);

    useEffect(() => {
        if (sent.current) {
            return;
        }

        sent.current = true;
        captureEvent('dashboard_setup_card_viewed', {
            ...setupCardProgress(checklist),
            next_step: checklist.steps.find((step) => !step.done)?.key ?? null,
        });
        getStorage('local')?.setItem(PENDING_KEY, '1');
    }, [checklist]);
}

/** The user hid the card for good. */
export function captureSetupCardDismissed(checklist: SetupChecklist): void {
    captureEvent(
        'dashboard_setup_card_dismissed',
        setupCardProgress(checklist),
    );
    getStorage('local')?.removeItem(PENDING_KEY);
}

/**
 * Once, the first time the dashboard loads without the card after this
 * browser showed it with steps left, while the card would still be open:
 * the server only stops sending it inside its window, and not dismissed,
 * when every step is done.
 *
 * `checklist` is undefined while the deferred prop loads, null when there is
 * no card to draw.
 */
export function useSetupCardCompletedEvent(
    checklist: SetupChecklist | null | undefined,
    user: User,
): void {
    useEffect(() => {
        if (checklist !== null) {
            return;
        }

        const storage = getStorage('local');

        if (!storage || storage.getItem(PENDING_KEY) === null) {
            return;
        }

        storage.removeItem(PENDING_KEY);

        if (isWithinSetupWindow(user)) {
            captureEvent('dashboard_setup_card_completed');
        }
    }, [checklist, user]);
}

function isWithinSetupWindow(user: User): boolean {
    const onboardedAt = user.onboarded_at;

    if (typeof onboardedAt !== 'string' || user.dashboard_setup_dismissed_at) {
        return false;
    }

    return Date.now() - new Date(onboardedAt).getTime() < WINDOW_DAYS * DAY_MS;
}
