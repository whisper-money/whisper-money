import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepPlan } from './step-plan';

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
        render(<StepPlan currencyCode="EUR" onContinue={vi.fn()} />);

        expect(
            screen.getByText(
                'Three steps, and the real number at the end of them.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/You want to/)).not.toBeInTheDocument();
    });
});
