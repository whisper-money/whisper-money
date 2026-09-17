import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepGuess } from './step-guess';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

const renderStep = (value?: number) => {
    const onContinue = vi.fn();

    render(
        <StepGuess currencyCode="EUR" value={value} onContinue={onContinue} />,
    );

    return { onContinue, slider: screen.getByRole('slider') };
};

describe('StepGuess', () => {
    it('shows the amount the slider is on', () => {
        const { slider } = renderStep();

        fireEvent.change(slider, { target: { value: '2500' } });

        expect(screen.getByText('2,500')).toBeInTheDocument();
    });

    // Every stored amount is in minor units, and this one is stored.
    it('hands the guess back in minor units', () => {
        const { onContinue, slider } = renderStep();

        fireEvent.change(slider, { target: { value: '1500' } });
        fireEvent.click(
            screen.getByRole('button', { name: 'Lock in my guess' }),
        );

        expect(onContinue).toHaveBeenCalledWith(150000);
    });

    it('starts from the guess already given', () => {
        renderStep(80000);

        expect(screen.getByText('800')).toBeInTheDocument();
    });
});
