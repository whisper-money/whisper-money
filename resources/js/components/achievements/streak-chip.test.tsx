import { type ChallengeMedal, type Challenges } from '@/types';
import { render, screen } from '@testing-library/react';
import { StrictMode, type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StreakChip } from './streak-chip';

/*
 * The pill in the header.
 *
 * Three states, and none of them may be mistaken for another: a ring filling
 * towards the next rung, the real medal in the ring's place while the sweep
 * catches up, and a full ring for a reader with nothing left to reach. A reader
 * with no live run gets no pill at all — there is nothing to keep.
 *
 * The fourth thing under test is the celebration: the pill flares only when the
 * run has grown past the last number this device saw for *this* user, and never
 * on a device that has never seen one.
 */

const USER_ID = 'a1b2c3d4';
const STREAK_SEEN_KEY = 'streak-seen';

const page = vi.hoisted(() => ({
    props: {} as {
        auth: { user: { id: string } };
        challenges: Challenges | null;
        locale: string;
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: page.props }),
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

// jsdom ships no `matchMedia`, which is what the hook reads. The states below
// are the same on either width; only the pixel sizes differ.
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

function medal(overrides: Partial<ChallengeMedal> = {}): ChallengeMedal {
    return {
        key: 'visits.3',
        track: 'visits',
        rarity: 'uncommon',
        icon: 'calendar-days',
        name: 'Visit streak',
        figure: { type: 'days', value: 30, currency: null },
        progress: { now: 12, goal: 30, unlocking: false },
        ...overrides,
    };
}

function draw(challenges: Challenges | null) {
    page.props = {
        auth: { user: { id: USER_ID } },
        challenges,
        locale: 'en-US',
    };

    return render(<StreakChip />);
}

function chip(challenges: Partial<Challenges> = {}): Challenges {
    return {
        visit_streak: 12,
        medals: [medal()],
        unlocked: null,
        uncategorized: null,
        ...challenges,
    };
}

/** How much of the ring is struck, as a percentage of the whole circle. */
function ringFill(container: HTMLElement): number | null {
    const dash = container
        .querySelector('circle[stroke-linecap="round"]')
        ?.getAttribute('stroke-dasharray');

    if (!dash) {
        return null;
    }

    const [struck, whole] = dash.split(' ').map(Number);

    return Math.round((struck / whole) * 100);
}

/** The medallion itself, which the ring is never mistaken for: it is bigger. */
const medallion = (container: HTMLElement) =>
    container.querySelector('svg[viewBox="0 0 48 48"]');

/** Whether the one-off "+1" flare is on, rather than the resting flicker. */
const celebrating = (container: HTMLElement) =>
    container.querySelector('.streak-flame-flare') !== null;

beforeEach(() => {
    localStorage.clear();
});

describe('StreakChip', () => {
    it('is not drawn at all with nothing handed to it', () => {
        const { container } = draw(null);

        expect(container).toBeEmptyDOMElement();
    });

    it('is not drawn without a live run', () => {
        // A run of zero is not a streak, and a pill counting zero is an
        // invitation to ignore it forever.
        const { container } = draw(chip({ visit_streak: 0 }));

        expect(container).toBeEmptyDOMElement();
    });

    it('counts the live run and fills the ring towards the next medal', () => {
        const { container } = draw(chip());

        expect(screen.getByText('12')).toBeInTheDocument();
        // Twelve of the thirty days the next medal asks for.
        expect(ringFill(container)).toBe(40);
        expect(medallion(container)).toBeNull();
    });

    it('counts the live run even when the medal is measured on a longer one', () => {
        const { container } = draw(
            chip({
                visit_streak: 5,
                medals: [
                    medal({
                        progress: { now: 12, goal: 30, unlocking: false },
                    }),
                ],
            }),
        );

        // Five days now, twelve at its best: the pill is about what there is to
        // lose today, and the ring is about how far the medal is.
        expect(screen.getByText('5')).toBeInTheDocument();
        expect(ringFill(container)).toBe(40);
    });

    it('swaps the ring for the real medal once the rung is reached', () => {
        const { container } = draw(
            chip({
                visit_streak: 31,
                medals: [
                    medal({ progress: { now: 31, goal: 30, unlocking: true } }),
                ],
            }),
        );

        expect(medallion(container)).not.toBeNull();
        expect(ringFill(container)).toBeNull();
        // The warm skin is the pill's own and does not change with the state:
        // it is the medal, not a fill, that marks this one out.
        expect(container.querySelector('button')).toHaveClass(
            'bg-[var(--streak-fill)]',
        );
    });

    it('fills the ring for a reader past the last rung', () => {
        // A finished track sends no medal: there is no goal left to fall short
        // of, and the number keeps climbing on its own.
        const { container } = draw(chip({ visit_streak: 400, medals: [] }));

        expect(screen.getByText('400')).toBeInTheDocument();
        expect(ringFill(container)).toBe(100);
        expect(medallion(container)).toBeNull();
    });

    it('plays the entrance once per page load, not on every visit', async () => {
        // No screen keeps the header across an Inertia visit, so the pill
        // remounts on every click. A plain mount animation would replay the
        // entrance each time; a fresh module is a fresh page load.
        vi.resetModules();
        const { StreakChip: Fresh } = await import('./streak-chip');

        page.props = {
            auth: { user: { id: USER_ID } },
            challenges: chip(),
            locale: 'en-US',
        };

        const first = render(<Fresh />);

        expect(first.container.querySelector('button')).toHaveClass(
            'animate-in',
        );
        expect(first.container.querySelector('.streak-ring')).not.toBeNull();

        first.unmount();

        const second = render(<Fresh />);

        expect(second.container.querySelector('button')).not.toHaveClass(
            'animate-in',
        );
        expect(second.container.querySelector('.streak-ring')).toBeNull();
    });

    it('does not celebrate on a device that has never seen this run', () => {
        // Nothing stored: the reader may have been on this run for weeks, and
        // cheering it now would be a lie.
        const { container } = draw(chip());

        expect(celebrating(container)).toBe(false);
        expect(localStorage.getItem(STREAK_SEEN_KEY)).toBe(`${USER_ID}:12`);
    });

    it('celebrates once when the run has grown since the last visit', () => {
        localStorage.setItem(STREAK_SEEN_KEY, `${USER_ID}:11`);

        const { container } = draw(chip());

        expect(celebrating(container)).toBe(true);
        expect(localStorage.getItem(STREAK_SEEN_KEY)).toBe(`${USER_ID}:12`);
    });

    it('survives an effect that runs twice for the same number', () => {
        // StrictMode replays effects in development, and the effect reads the
        // very value it then overwrites: a second pass must not talk itself out
        // of the celebration the first one earned.
        localStorage.setItem(STREAK_SEEN_KEY, `${USER_ID}:11`);
        page.props = {
            auth: { user: { id: USER_ID } },
            challenges: chip(),
            locale: 'en-US',
        };

        const { container } = render(
            <StrictMode>
                <StreakChip />
            </StrictMode>,
        );

        expect(celebrating(container)).toBe(true);
    });

    it('does not celebrate the same number twice', () => {
        // Which is what every navigation after the first one of the day is.
        localStorage.setItem(STREAK_SEEN_KEY, `${USER_ID}:12`);

        const { container } = draw(chip());

        expect(celebrating(container)).toBe(false);
    });

    it('does not celebrate another account\u2019s number', () => {
        // One browser, two logins: the number left behind by the other account
        // says nothing about this one.
        localStorage.setItem(STREAK_SEEN_KEY, 'someone-else:3');

        const { container } = draw(chip());

        expect(celebrating(container)).toBe(false);
        expect(localStorage.getItem(STREAK_SEEN_KEY)).toBe(`${USER_ID}:12`);
    });

    it('celebrates a run restarted after a break', () => {
        // The break stored a zero, so day one of the next run is a rise.
        localStorage.setItem(STREAK_SEEN_KEY, `${USER_ID}:0`);

        const { container } = draw(chip({ visit_streak: 1 }));

        expect(celebrating(container)).toBe(true);
    });

    it('records the broken run so the next day reads as a rise', () => {
        localStorage.setItem(STREAK_SEEN_KEY, `${USER_ID}:12`);

        // No pill is drawn for a dead run, but the number is still the last one
        // the reader saw: leaving 12 behind would swallow the whole next run.
        draw(chip({ visit_streak: 0 }));

        expect(localStorage.getItem(STREAK_SEEN_KEY)).toBe(`${USER_ID}:0`);
    });
});
