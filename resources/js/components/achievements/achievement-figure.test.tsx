import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AchievementFigure } from './achievement-figure';

/*
 * A medal's number is written by the client so privacy mode can blank it, which
 * means every shape of figure passes through here on its way to the screen.
 * Money is the one that bites: a milestone is written without decimals, and
 * asking Intl for at most none while the currency still wants two throws.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

describe('AchievementFigure', () => {
    it('writes a money milestone whole, with no decimals', () => {
        render(
            <AchievementFigure
                figure={{ type: 'money', value: 2500000, currency: 'EUR' }}
            />,
        );

        expect(screen.getByText(/€25,000$/)).toBeInTheDocument();
    });

    it('writes a currency with no minor units at its own scale', () => {
        render(
            <AchievementFigure
                figure={{ type: 'money', value: 20000000, currency: 'COP' }}
            />,
        );

        expect(screen.getByText(/20,000,000/)).toBeInTheDocument();
    });

    it('writes a rate, a run of months and a plain count', () => {
        const { rerender } = render(
            <AchievementFigure
                figure={{ type: 'percent', value: 50, currency: null }}
            />,
        );
        expect(screen.getByText('50%')).toBeInTheDocument();

        rerender(
            <AchievementFigure
                figure={{ type: 'months', value: 6, currency: null }}
            />,
        );
        expect(screen.getByText('6 months')).toBeInTheDocument();

        rerender(
            <AchievementFigure
                figure={{ type: 'count', value: 1000, currency: null }}
            />,
        );
        expect(screen.getByText('1,000')).toBeInTheDocument();
    });

    it('draws nothing for a medal with no number to it', () => {
        const { container } = render(<AchievementFigure figure={null} />);

        expect(container).toBeEmptyDOMElement();
    });
});
