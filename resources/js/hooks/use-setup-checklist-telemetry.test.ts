import {
    captureSetupCardDismissed,
    useSetupCardCompletedEvent,
    useSetupCardViewedEvent,
} from '@/hooks/use-setup-checklist-telemetry';
import { type User } from '@/types';
import { type SetupChecklist } from '@/types/dashboard';
import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const captureEvent = vi.hoisted(() => vi.fn());

vi.mock('@/lib/posthog', () => ({ captureEvent }));

const checklist: SetupChecklist = {
    steps: [{ key: 'categorize', done: false, count: 3 }],
};

const daysAgo = (days: number) =>
    new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString();

function user(overrides: Record<string, unknown> = {}): User {
    return {
        id: 'user-1',
        onboarded_at: daysAgo(2),
        dashboard_setup_dismissed_at: null,
        ...overrides,
    } as unknown as User;
}

function completedEvents() {
    return captureEvent.mock.calls.filter(
        ([event]) => event === 'dashboard_setup_card_completed',
    );
}

/** This browser showed the card with steps left on an earlier visit. */
function viewTheCard() {
    renderHook(() => useSetupCardViewedEvent(checklist, 'user-1')).unmount();
}

describe('useSetupCardCompletedEvent', () => {
    beforeEach(() => {
        captureEvent.mockReset();
        localStorage.clear();
    });

    it('reports the card completed once when it stops coming after being shown', () => {
        viewTheCard();

        const { rerender } = renderHook(
            ({ current }) => useSetupCardCompletedEvent(current, user()),
            {
                initialProps: {
                    current: undefined as SetupChecklist | null | undefined,
                },
            },
        );

        expect(completedEvents()).toHaveLength(0);

        rerender({ current: null });
        rerender({ current: null });
        renderHook(() => useSetupCardCompletedEvent(null, user()));

        expect(completedEvents()).toEqual([['dashboard_setup_card_completed']]);
    });

    it('says nothing while the card is still there', () => {
        viewTheCard();

        renderHook(() => useSetupCardCompletedEvent(checklist, user()));

        expect(completedEvents()).toHaveLength(0);
    });

    it('says nothing when this browser never showed the card', () => {
        renderHook(() => useSetupCardCompletedEvent(null, user()));

        expect(completedEvents()).toHaveLength(0);
    });

    it('says nothing when the card went away because its window ended', () => {
        viewTheCard();

        renderHook(() =>
            useSetupCardCompletedEvent(
                null,
                user({ onboarded_at: daysAgo(15) }),
            ),
        );

        expect(completedEvents()).toHaveLength(0);
    });

    it('says nothing to another user of the same browser', () => {
        viewTheCard();

        renderHook(() =>
            useSetupCardCompletedEvent(null, user({ id: 'user-2' })),
        );

        expect(completedEvents()).toHaveLength(0);
    });

    it('says nothing when the card was dismissed, here or elsewhere', () => {
        viewTheCard();
        renderHook(() =>
            useSetupCardCompletedEvent(
                null,
                user({ dashboard_setup_dismissed_at: daysAgo(0) }),
            ),
        );

        viewTheCard();
        captureSetupCardDismissed(checklist, 'user-1');
        renderHook(() => useSetupCardCompletedEvent(null, user()));

        expect(completedEvents()).toHaveLength(0);
    });
});
