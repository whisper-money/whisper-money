import { type AchievementMedal } from '@/types';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TrackMedals } from './track-medals';

/*
 * The locked tail.
 *
 * A track is a ladder, and the far end of it is a run of question marks that
 * says nothing. Only what has been earned and the next rung are drawn; the rest
 * is one slot carrying the count, which opens the ladder in place.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

function track(states: AchievementMedal['state'][]): AchievementMedal[] {
    return states.map((state, index) => ({
        key: `transactions.${index}`,
        rarity: 'common',
        share: null,
        state,
        name: state === 'locked' ? null : `Rung ${index}`,
        icon: null,
        figure: null,
        reached: null,
        achieved_on: state === 'earned' ? '2024-03' : null,
        progress: null,
    })) as AchievementMedal[];
}

describe('TrackMedals', () => {
    it('draws what has been earned and the next rung, and folds the rest away', () => {
        render(
            <TrackMedals
                medals={track(['earned', 'next', 'locked', 'locked', 'locked'])}
            />,
        );

        expect(screen.getByText('Rung 0')).toBeInTheDocument();
        expect(screen.getByText('Rung 1')).toBeInTheDocument();
        expect(screen.queryByText('???')).toBeNull();
        expect(screen.getByText('+3 to come')).toBeInTheDocument();
    });

    it('opens the rest of the ladder in place, and folds it back', () => {
        render(<TrackMedals medals={track(['earned', 'next', 'locked'])} />);

        fireEvent.click(screen.getByRole('button', { name: /to come/ }));

        expect(screen.getByText('???')).toBeInTheDocument();
        expect(screen.queryByText('+1 to come')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Show less/ }));

        expect(screen.queryByText('???')).toBeNull();
        expect(screen.getByText('+1 to come')).toBeInTheDocument();
    });

    it('says nothing about a tail a completed track does not have', () => {
        render(<TrackMedals medals={track(['earned', 'earned'])} />);

        expect(screen.queryByRole('button', { name: /to come/ })).toBeNull();
    });

    // The tail folds for everyone, newcomer and veteran alike: a ladder with
    // nothing earned yet is the case the collapse was drawn for.
    it('folds the tail on day one too, leaving only the next rung', () => {
        render(<TrackMedals medals={track(['next', 'locked', 'locked'])} />);

        expect(screen.getByText('Rung 0')).toBeInTheDocument();
        expect(screen.getByText('+2 to come')).toBeInTheDocument();
    });
});
