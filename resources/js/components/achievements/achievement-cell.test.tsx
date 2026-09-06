import { type AchievementMedal } from '@/types';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AchievementCell } from './achievement-cell';

/*
 * The three states of a cell.
 *
 * A locked one is three question marks and nothing else — the server does not
 * send its name, so the cell has nothing to leak. The next one of its track is
 * the exception: named, with the figure to reach and, where the server can say
 * cheaply, how far along the reader is. What separates it from an earned medal
 * is that there is nothing to share yet and nothing to date.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

function medal(overrides: Partial<AchievementMedal> = {}): AchievementMedal {
    return {
        key: 'visits.3',
        rarity: 'uncommon',
        share: 12,
        state: 'locked',
        name: null,
        icon: null,
        figure: null,
        reached: null,
        achieved_on: null,
        progress: null,
        ...overrides,
    } as AchievementMedal;
}

const next = {
    state: 'next',
    name: 'Visit streak',
    icon: 'calendar-days',
    figure: { type: 'days', value: 30, currency: null },
} as const;

describe('AchievementCell', () => {
    it('says nothing at all about a locked medal', () => {
        render(<AchievementCell medal={medal()} />);

        expect(screen.getByText('???')).toBeInTheDocument();
        expect(screen.queryByLabelText('Share this medal')).toBeNull();
    });

    it('names the next medal and the figure to reach, without a way to share it', () => {
        render(<AchievementCell medal={medal(next)} />);

        expect(screen.getByText('Visit streak')).toBeInTheDocument();
        expect(screen.getByText('30 days')).toBeInTheDocument();
        expect(screen.queryByText('???')).toBeNull();
        // Nothing has happened yet, so there is nothing to post.
        expect(screen.queryByLabelText('Share this medal')).toBeNull();
    });

    it('fills the bar to how far along the reader is', () => {
        const { container } = render(
            <AchievementCell
                medal={medal({
                    ...next,
                    progress: { now: 12, goal: 30, unlocking: false },
                })}
            />,
        );

        expect(screen.getByText('12 of 30')).toBeInTheDocument();
        expect(container.querySelector('.bg-primary')).toHaveStyle({
            width: '40%',
        });
    });

    it('writes a count the way the figure above it is written', () => {
        render(
            <AchievementCell
                medal={medal({
                    ...next,
                    figure: { type: 'count', value: 10000, currency: null },
                    progress: { now: 4321, goal: 10000, unlocking: false },
                })}
            />,
        );

        expect(screen.getByText('4,321 of 10,000')).toBeInTheDocument();
    });

    it('fills the bar and names the sweep once the reader is already past the goal', () => {
        const { container } = render(
            <AchievementCell
                medal={medal({
                    ...next,
                    progress: { now: 35, goal: 30, unlocking: true },
                })}
            />,
        );

        // Never "35 of 30", and never trimmed back to "30 of 30" either.
        expect(screen.getByText('Unlocks tonight')).toBeInTheDocument();
        expect(container.querySelector('.bg-primary')).toHaveStyle({
            width: '100%',
        });
    });
});
