import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { StepGuess } from './step-guess';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

// jsdom ships no `matchMedia`; the desktop dialog is the one under test.
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

const CURRENCIES = [
    { code: 'EUR', name: 'Euro' },
    { code: 'USD', name: 'US Dollar' },
    { code: 'INR', name: 'Indian Rupee' },
];

/** Plays the page's part: a picked currency comes back as the new prop. */
function Harness({
    value,
    onContinue,
    onCurrencyChange,
}: {
    value?: number;
    onContinue: (spendingGuess: number) => void;
    onCurrencyChange: (currencyCode: string) => void;
}) {
    const [currencyCode, setCurrencyCode] = useState('EUR');

    return (
        <StepGuess
            currencyCode={currencyCode}
            currencies={CURRENCIES}
            value={value}
            onCurrencyChange={(code) => {
                onCurrencyChange(code);
                setCurrencyCode(code);
            }}
            onContinue={onContinue}
        />
    );
}

const renderStep = (value?: number) => {
    const onContinue = vi.fn();
    const onCurrencyChange = vi.fn();

    render(
        <Harness
            value={value}
            onContinue={onContinue}
            onCurrencyChange={onCurrencyChange}
        />,
    );

    return { onContinue, onCurrencyChange, slider: screen.getByRole('slider') };
};

const pickCurrency = (search: string, name: string) => {
    fireEvent.click(
        screen.getByRole('button', { name: 'Change currency, currently Euro' }),
    );
    fireEvent.change(screen.getByPlaceholderText('Search currency...'), {
        target: { value: search },
    });
    fireEvent.click(screen.getByRole('option', { name: new RegExp(name) }));
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

    it('names the currency the guess is in', () => {
        renderStep();

        expect(
            screen.getByRole('button', {
                name: 'Change currency, currently Euro',
            }),
        ).toHaveTextContent(/EUR.*Euro/);
    });

    it('lets the user switch to a currency found by its name', () => {
        const { onCurrencyChange } = renderStep();

        pickCurrency('rupee', 'Indian Rupee');

        expect(onCurrencyChange).toHaveBeenCalledWith('INR');
        expect(
            screen.getByRole('button', {
                name: 'Change currency, currently Indian Rupee',
            }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    // A guess in euros means nothing in rupees, so the slider starts over on
    // the rupee range instead of keeping the euro number.
    it('resets the slider to the new currency range', () => {
        const { slider } = renderStep();

        fireEvent.change(slider, { target: { value: '2500' } });
        pickCurrency('INR', 'Indian Rupee');

        expect(slider).toHaveAttribute('min', '15000');
        expect(slider).toHaveAttribute('max', '250000');
        expect(slider).toHaveValue('60000');
        expect(screen.getByText('60,000')).toBeInTheDocument();
        expect(screen.getByText('₹')).toBeInTheDocument();
        expect(screen.getByText('₹250,000+')).toBeInTheDocument();
    });

    // The page rolls a failed switch back through the prop alone, and the
    // slider has to follow it rather than keep the other currency's number.
    it('starts over whenever the currency comes back changed', () => {
        const props = {
            currencies: CURRENCIES,
            onCurrencyChange: vi.fn(),
            onContinue: vi.fn(),
        };
        const { rerender } = render(
            <StepGuess currencyCode="INR" {...props} />,
        );
        const slider = screen.getByRole('slider');

        fireEvent.change(slider, { target: { value: '100000' } });
        rerender(<StepGuess currencyCode="EUR" {...props} />);

        expect(slider).toHaveAttribute('max', '5000');
        expect(slider).toHaveValue('1200');
    });

    it('does not save anything when the current currency is picked again', () => {
        const { onCurrencyChange } = renderStep();

        pickCurrency('EUR', 'Euro');

        expect(onCurrencyChange).not.toHaveBeenCalled();
    });
});
