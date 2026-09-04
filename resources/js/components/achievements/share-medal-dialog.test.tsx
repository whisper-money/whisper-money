import { type AchievementMedal } from '@/types';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ShareMedalDialog } from './share-medal-dialog';

/*
 * The share dialog.
 *
 * Two rules matter here. The switch that keeps an amount off the picture only
 * exists on the medals that have an amount — a savings rate has nothing to hide
 * and a checkbox offering to hide it would be nonsense. And saving the picture
 * is always available: it is the whole fallback for a browser whose share sheet
 * cannot carry files, so it can never be the thing behind the feature test.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

function medal(overrides: Partial<AchievementMedal> = {}): AchievementMedal {
    return {
        key: 'monthly_saving.4',
        rarity: 'epic',
        share: 4,
        locked: false,
        name: 'Saved in a month',
        icon: 'piggy-bank',
        figure: { type: 'money', value: 500000, currency: 'EUR' },
        reached: null,
        achieved_on: '2025-03-01',
        ...overrides,
    } as AchievementMedal;
}

function open(medalToShare: AchievementMedal) {
    render(
        <ShareMedalDialog medal={medalToShare}>
            <button>share</button>
        </ShareMedalDialog>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'share' }));
}

describe('ShareMedalDialog', () => {
    it('offers both shapes and both skins', () => {
        open(medal());

        expect(screen.getByRole('radio', { name: /4:5/ })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /9:16/ })).toBeInTheDocument();
        expect(
            screen.getByRole('radio', { name: 'Light' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Dark' })).toBeInTheDocument();
    });

    it('lets a money medal keep its amount off the picture', () => {
        open(medal());

        expect(
            screen.getByRole('checkbox', { name: /Leave the amount off/ }),
        ).toBeInTheDocument();
    });

    it('does not offer to hide a figure that was never an amount', () => {
        open(
            medal({
                key: 'savings_rate.4',
                name: 'Savings rate',
                figure: { type: 'percent', value: 75, currency: null },
            }),
        );

        expect(
            screen.queryByRole('checkbox', { name: /Leave the amount off/ }),
        ).not.toBeInTheDocument();
    });

    it('always offers to save the picture, whatever the browser can share', () => {
        open(medal());

        const save = screen.getByRole('link', { name: /Save the picture/ });
        expect(save).toHaveAttribute(
            'href',
            '/progress/medal/monthly_saving.4/feed/light?amount=1&lang=en-US',
        );
        expect(save).toHaveAttribute(
            'download',
            'whisper-money-monthly_saving-4-feed-light.png',
        );
    });

    it('puts the language in the URL so a switch is not answered from cache', () => {
        // The server draws in the reader's language whatever the URL says, but
        // without the language in it the URL is identical in every language and
        // the browser hands back the copy it already has.
        open(medal());

        expect(
            screen.getByRole('link', { name: /Save the picture/ }),
        ).toHaveAttribute('href', expect.stringContaining('lang=en-US'));
    });

    it('asks the server to leave the amount out once the switch is on', () => {
        open(medal());

        fireEvent.click(
            screen.getByRole('checkbox', { name: /Leave the amount off/ }),
        );

        expect(
            screen.getByRole('link', { name: /Save the picture/ }),
        ).toHaveAttribute(
            'href',
            '/progress/medal/monthly_saving.4/feed/light?amount=0&lang=en-US',
        );
    });
});
