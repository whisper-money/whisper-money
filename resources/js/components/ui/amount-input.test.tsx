import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AmountInput } from './amount-input';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

describe('AmountInput sign toggle', () => {
    it('is hidden unless allowNegative is set', () => {
        const { rerender } = render(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="USD" />,
        );
        expect(screen.queryByRole('button')).toBeNull();

        rerender(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="USD" allowNegative />,
        );
        expect(screen.getByRole('button')).toBeInTheDocument();
    });

    it('flips a typed positive amount to negative', () => {
        const onChange = vi.fn();
        render(
            <AmountInput value={0} onChange={onChange} currencyCode="USD" allowNegative />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '25' } });

        fireEvent.click(screen.getByRole('button'));

        expect(onChange).toHaveBeenCalledWith(-2500);
    });

    it('flips a negative amount back to positive', () => {
        const onChange = vi.fn();
        render(
            <AmountInput value={0} onChange={onChange} currencyCode="USD" allowNegative />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '-25' } });

        fireEvent.click(screen.getByRole('button'));

        expect(onChange).toHaveBeenLastCalledWith(2500);
    });

    it('keeps the negative sign when focusing after toggling an empty field', () => {
        render(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="USD" allowNegative />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.click(screen.getByRole('button'));
        fireEvent.focus(input);

        expect(input).toHaveValue('-');
    });
});
