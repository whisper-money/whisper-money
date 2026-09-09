import { type ChallengeMedal, type Challenges } from '@/types';
import { render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { Toaster } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * "You just earned a medal".
 *
 * Visit medals land on the request that earns them, so the prop carrying the
 * last one is always set for a reader who holds any. What decides whether it is
 * news is this device's memory of the last one announced: a medal nobody has
 * been told about is a moment, the same medal on the next page is not, and a
 * device that has never been told anything has no moment to replay.
 */

const USER_ID = 'a1b2c3d4';
const MEDAL_SEEN_KEY = 'medal-seen';

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

function medal(overrides: Partial<ChallengeMedal> = {}): ChallengeMedal {
    return {
        key: 'visits.3',
        track: 'visits',
        rarity: 'uncommon',
        icon: 'calendar-days',
        name: 'Visit streak',
        figure: { type: 'days', value: 30, currency: null },
        progress: null,
        ...overrides,
    };
}

function challenges(unlocked: ChallengeMedal | null): Challenges {
    return {
        visit_streak: 30,
        medals: [],
        unlocked,
        uncategorized: null,
    };
}

/**
 * The shell, in `app.tsx`'s own order: the toast component first, the toaster
 * after it. Re-imported per test because the once-per-session latch is module
 * state, exactly as the categorize prompt's suite does it.
 */
async function mountShell(
    props: Challenges | null,
    userId: string | undefined = USER_ID,
) {
    const { MedalUnlockedToast } = await import('./medal-unlocked-toast');

    return render(
        <>
            <MedalUnlockedToast initialChallenges={props} userId={userId} />
            <Toaster />
        </>,
    );
}

const announced = () => screen.queryByText('Medal unlocked');

describe('MedalUnlockedToast', () => {
    beforeEach(() => {
        vi.resetModules();
        localStorage.clear();
    });

    it('says nothing on a device that has never been told anything', async () => {
        // The shelf may be years old. Greeting a new phone with a medal from
        // March would be a lie, so the first visit only takes note.
        await mountShell(challenges(medal()));

        await waitFor(() =>
            expect(localStorage.getItem(MEDAL_SEEN_KEY)).toBe(
                `${USER_ID}:visits.3`,
            ),
        );
        expect(announced()).toBeNull();
    });

    it('announces a medal this device has not seen before', async () => {
        localStorage.setItem(MEDAL_SEEN_KEY, `${USER_ID}:visits.2`);

        await mountShell(challenges(medal()));

        expect(await screen.findByText('Medal unlocked')).toBeInTheDocument();
        expect(screen.getByText('Visit streak')).toBeInTheDocument();
        expect(localStorage.getItem(MEDAL_SEEN_KEY)).toBe(
            `${USER_ID}:visits.3`,
        );
    });

    it('says nothing about the same medal twice', async () => {
        // Which is what every page after the one that earned it is.
        localStorage.setItem(MEDAL_SEEN_KEY, `${USER_ID}:visits.3`);

        await mountShell(challenges(medal()));

        await waitFor(() => expect(announced()).toBeNull());
    });

    it('ignores the medal another account left behind', async () => {
        // One browser, two logins. The other reader's shelf says nothing about
        // this one's, and replaying it would be somebody else's moment.
        localStorage.setItem(MEDAL_SEEN_KEY, 'someone-else:visits.1');

        await mountShell(challenges(medal()));

        await waitFor(() =>
            expect(localStorage.getItem(MEDAL_SEEN_KEY)).toBe(
                `${USER_ID}:visits.3`,
            ),
        );
        expect(announced()).toBeNull();
    });

    it('says nothing for a reader holding no medals at all', async () => {
        await mountShell(challenges(null));

        await waitFor(() => expect(announced()).toBeNull());
        expect(localStorage.getItem(MEDAL_SEEN_KEY)).toBeNull();
    });

    it('says nothing with no challenges at all', async () => {
        await mountShell(null);

        await waitFor(() => expect(announced()).toBeNull());
    });
});
