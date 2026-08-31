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

        expect(onChange).toHaveBeenCalledWith(-2500, false);
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

        expect(onChange).toHaveBeenLastCalledWith(2500, false);
    });

    it('reports an empty field and a typed zero differently', () => {
        const onChange = vi.fn();
        render(
            <AmountInput value={0} onChange={onChange} currencyCode="USD" />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.blur(input);
        expect(onChange).toHaveBeenLastCalledWith(0, true);

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '0' } });
        fireEvent.blur(input);
        expect(onChange).toHaveBeenLastCalledWith(0, false);
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

describe('AmountInput commitOnChange', () => {
    it('waits for blur by default, so nothing reacts mid-typing', () => {
        const onChange = vi.fn();
        render(
            <AmountInput value={0} onChange={onChange} currencyCode="EUR" />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '30' } });

        expect(onChange).not.toHaveBeenCalled();

        fireEvent.blur(input);

        expect(onChange).toHaveBeenCalledWith(3000, false);
    });

    it('reports every keystroke when asked, for a running total that has to keep up', () => {
        const onChange = vi.fn();
        render(
            <AmountInput
                value={0}
                onChange={onChange}
                currencyCode="EUR"
                commitOnChange
            />,
        );

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '3' } });
        fireEvent.change(input, { target: { value: '30' } });

        expect(onChange).toHaveBeenNthCalledWith(1, 300, false);
        expect(onChange).toHaveBeenNthCalledWith(2, 3000, false);
    });
});

describe('AmountInput per-currency scale', () => {
    it('emits whole units for a zero-decimal currency', () => {
        const onChange = vi.fn();
        render(<AmountInput value={0} onChange={onChange} currencyCode="COP" />);

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '25000' } });
        fireEvent.blur(input);

        expect(onChange).toHaveBeenCalledWith(25000, false);
    });

    it('emits satoshis for BTC', () => {
        const onChange = vi.fn();
        render(<AmountInput value={0} onChange={onChange} currencyCode="BTC" />);

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '0.00123456' } });
        fireEvent.blur(input);

        expect(onChange).toHaveBeenCalledWith(123456, false);
    });

    it('adds up in major units whatever the scale', () => {
        const onChange = vi.fn();
        render(<AmountInput value={0} onChange={onChange} currencyCode="COP" />);

        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '20000+5000' } });
        fireEvent.blur(input);

        expect(onChange).toHaveBeenCalledWith(25000, false);
    });

    it('shapes the placeholder to the currency precision', () => {
        const { rerender } = render(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="COP" />,
        );
        expect(screen.getByRole('textbox')).toHaveAttribute('placeholder', '0');

        rerender(<AmountInput value={0} onChange={vi.fn()} currencyCode="BTC" />);
        expect(screen.getByRole('textbox')).toHaveAttribute(
            'placeholder',
            '0.00000000',
        );

        rerender(<AmountInput value={0} onChange={vi.fn()} currencyCode="EUR" />);
        expect(screen.getByRole('textbox')).toHaveAttribute(
            'placeholder',
            '0.00',
        );
    });

    it('renders a stored value back at the currency scale', () => {
        render(<AmountInput value={123456} onChange={vi.fn()} currencyCode="BTC" />);

        expect(screen.getByRole('textbox')).toHaveValue('0.00123456');
    });
});

describe('AmountInput separators', () => {
    const typeAndCommit = (typed: string, currencyCode: string) => {
        const onChange = vi.fn();
        const { unmount } = render(
            <AmountInput value={0} onChange={onChange} currencyCode={currencyCode} />,
        );
        const input = screen.getByRole('textbox');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: typed } });
        fireEvent.blur(input);
        unmount();

        return onChange.mock.calls[0][0];
    };

    it('reads every separator as grouping in a zero-decimal currency', () => {
        // Colombians type "1.234.567" for a million-and-change pesos. There is
        // no centavo, so no separator can be a decimal point.
        expect(typeAndCommit('1.234.567', 'COP')).toBe(1234567);
        expect(typeAndCommit('1.234', 'COP')).toBe(1234);
        expect(typeAndCommit('25000', 'COP')).toBe(25000);
    });

    it('still reads the last separator as the decimal point', () => {
        expect(typeAndCommit('1.234,56', 'EUR')).toBe(123456);
        expect(typeAndCommit('1,234.56', 'EUR')).toBe(123456);
        expect(typeAndCommit('12,50', 'EUR')).toBe(1250);
        expect(typeAndCommit('12.50', 'EUR')).toBe(1250);
    });

    it('treats a repeated separator as grouping', () => {
        expect(typeAndCommit('1.234.567', 'EUR')).toBe(123456700);
    });
});

describe('AmountInput currency symbol spacing', () => {
    const reservedCharacters = (padding: string): number =>
        Number(padding.match(/([\d.]+)ch/)?.[1] ?? 0);

    it('reserves room for the whole symbol, not just one character', () => {
        const { rerender } = render(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="EUR" />,
        );
        const euro = screen.getByRole('textbox').style.paddingLeft;

        rerender(<AmountInput value={0} onChange={vi.fn()} currencyCode="BTC" />);
        const bitcoin = screen.getByRole('textbox').style.paddingLeft;

        expect(reservedCharacters(euro)).toBe(1);
        expect(reservedCharacters(bitcoin)).toBeGreaterThan(
            reservedCharacters(euro),
        );
    });

    it('stacks the symbol room on top of the sign toggle room', () => {
        const { rerender } = render(
            <AmountInput value={0} onChange={vi.fn()} currencyCode="BTC" />,
        );
        const withoutToggle = screen.getByRole('textbox').style.paddingLeft;

        rerender(
            <AmountInput
                value={0}
                onChange={vi.fn()}
                currencyCode="BTC"
                allowNegative
            />,
        );
        const withToggle = screen.getByRole('textbox').style.paddingLeft;

        expect(withToggle).not.toBe(withoutToggle);
        expect(reservedCharacters(withToggle)).toBe(
            reservedCharacters(withoutToggle),
        );
    });
});
