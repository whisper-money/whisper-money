import { captureEvent } from '@/lib/posthog';
import { getStorage } from '@/lib/safe-storage';
import { type User } from '@/types';
import { type SetupChecklist } from '@/types/dashboard';
import { useEffect, useRef } from 'react';

/**
 * Set while this browser has shown the user the "Finish setting up" card with
 * steps left, so that the visit on which the server stops sending it can tell
 * why. One per user, since a browser can be shared between accounts.
 */
const pendingKey = (userId: User['id']) =>
    `dashboard-setup-card-pending:${userId}`;

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

/**
 * Once per dashboard visit (the card's mount): the card was drawn, and how
 * far along it was. Later reloads of the same visit do not send it again.
 */
export function useSetupCardViewedEvent(
    checklist: SetupChecklist,
    userId: User['id'],
): void {
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
        getStorage('local')?.setItem(pendingKey(userId), '1');
    }, [checklist, userId]);
}

/**
 * The user hid the card for good: reported, and the card no longer counts as
 * pending, so its absence from now on is not read as completed.
 */
export function captureSetupCardDismissed(
    checklist: SetupChecklist,
    userId: User['id'],
): void {
    captureEvent(
        'dashboard_setup_card_dismissed',
        setupCardProgress(checklist),
    );
    getStorage('local')?.removeItem(pendingKey(userId));
}

/**
 * Once, the first time the dashboard loads without the card after this
 * browser showed it with steps left, while the card would still be open:
 * the server only stops sending it inside its window, and not dismissed,
 * when every step is done.
 *
 * `checklist` is undefined while the deferred prop loads, null when there is
 * no card to draw.
 *
 * A lower bound, sent on the visit after the last step was done rather than
 * when it was: a user who finishes on another browser, or comes back after
 * the window, is not counted.
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

        const key = pendingKey(user.id);

        if (!storage || storage.getItem(key) === null) {
            return;
        }

        storage.removeItem(key);

        if (isWithinSetupWindow(user)) {
            captureEvent('dashboard_setup_card_completed');
        }
    }, [checklist, user]);
}

function isWithinSetupWindow(user: User): boolean {
    if (!user.onboarded_at || user.dashboard_setup_dismissed_at) {
        return false;
    }

    return (
        Date.now() - new Date(user.onboarded_at).getTime() <
        WINDOW_DAYS * DAY_MS
    );
}
