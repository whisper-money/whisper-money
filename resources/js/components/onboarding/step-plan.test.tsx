import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepPlan } from './step-plan';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

describe('StepPlan', () => {
    it('reads the answers back in the sentence they were given for', () => {
        render(
            <StepPlan
                goal="understand where it all goes"
                spendingGuess={120000}
                currencyCode="EUR"
                bankOffered
                onContinue={vi.fn()}
            />,
        );

        expect(
            screen.getByText(/understand where it all goes/),
        ).toBeInTheDocument();
        expect(screen.getByText(/€1,200/)).toBeInTheDocument();
    });

    // A deep link onto this step answers nothing, and the sentence must not
    // tell the user they want to "undefined".
    it('falls back to a plain line when there is nothing to read back', () => {
        render(
            <StepPlan currencyCode="EUR" bankOffered onContinue={vi.fn()} />,
        );

        expect(
            screen.getByText(
                'Three steps, and the real number at the end of them.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/You want to/)).not.toBeInTheDocument();
    });

    // The free card is never offered a bank connection or the AI pass, so the
    // plan must not open by promising both.
    it('promises a free signup the flow they will actually get', () => {
        render(
            <StepPlan
                currencyCode="EUR"
                isFreePlan
                bankOffered={false}
                onContinue={vi.fn()}
            />,
        );

        expect(
            screen.getByText('A year of your spending, from a file'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/at your bank's own login/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/already filed when you arrive/),
        ).not.toBeInTheDocument();
    });

    // A paid signup the hub offers no bank to — a country no bank of ours
    // reaches, or an install without open banking — reads about a file, and
    // still gets the AI pass.
    it('promises a file when no bank is offered, even on a paid plan', () => {
        render(
            <StepPlan
                currencyCode="EUR"
                bankOffered={false}
                onContinue={vi.fn()}
            />,
        );

        expect(
            screen.getByText('A year of your spending, from a file'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'The export your bank already offers, read in one go.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/at your bank's own login/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Every movement already filed when you arrive'),
        ).toBeInTheDocument();
    });

    it('still promises the paid signup the bank and the AI pass', () => {
        render(
            <StepPlan currencyCode="EUR" bankOffered onContinue={vi.fn()} />,
        );

        expect(
            screen.getByText('A year of your spending, without typing it'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Every movement already filed when you arrive'),
        ).toBeInTheDocument();
    });
});
