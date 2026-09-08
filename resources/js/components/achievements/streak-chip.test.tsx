import { type ChallengeMedal, type Challenges } from '@/types';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { StreakChip } from './streak-chip';

/*
 * The pill in the header.
 *
 * Three states, and none of them may be mistaken for another: a ring filling
 * towards the next rung, the real medal in the ring's place while the sweep
 * catches up, and a full ring for a reader with nothing left to reach. A reader
 * with no live run gets no pill at all — there is nothing to keep.
 */

const page = vi.hoisted(() => ({
    props: {} as { challenges: Challenges | null; locale: string },
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
    page.props = { challenges, locale: 'en-US' };

    return render(<StreakChip />);
}

function chip(challenges: Partial<Challenges> = {}): Challenges {
    return {
        visit_streak: 12,
        medals: [medal()],
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

describe('StreakChip', () => {
    it('is not drawn at all with the feature switched off', () => {
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
                medals: [medal({ progress: { now: 12, goal: 30, unlocking: false } })],
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
                medals: [medal({ progress: { now: 31, goal: 30, unlocking: true } })],
            }),
        );

        expect(medallion(container)).not.toBeNull();
        expect(ringFill(container)).toBeNull();
        expect(container.querySelector('button')).toHaveClass('bg-muted');
    });

    it('fills the ring for a reader past the last rung', () => {
        // A finished track sends no medal: there is no goal left to fall short
        // of, and the number keeps climbing on its own.
        const { container } = draw(chip({ visit_streak: 400, medals: [] }));

        expect(screen.getByText('400')).toBeInTheDocument();
        expect(ringFill(container)).toBe(100);
        expect(medallion(container)).toBeNull();
    });
});
