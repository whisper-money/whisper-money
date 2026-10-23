import { type SignedAmountRange } from '@/lib/amount-filter';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { AmountFilterFields } from './transaction-filters-amount';

const empty: SignedAmountRange = { amountMin: null, amountMax: null };

/** A parent that owns the range, like the filters panel does. */
function Harness({
    initial,
    onChange,
}: {
    initial: SignedAmountRange;
    onChange: (range: SignedAmountRange) => void;
}) {
    const [range, setRange] = useState(initial);

    return (
        <>
            <AmountFilterFields
                {...range}
                onChange={(next) => {
                    setRange(next);
                    onChange(next);
                }}
            />
            <button type="button" onClick={() => setRange(empty)}>
                Clear
            </button>
            <button
                type="button"
                onClick={() => setRange({ amountMin: 100, amountMax: null })}
            >
                Load saved
            </button>
        </>
    );
}

function renderHarness(initial: SignedAmountRange = empty) {
    const onChange = vi.fn();
    render(<Harness initial={initial} onChange={onChange} />);
    return onChange;
}

const min = () => screen.getByTestId('filter-amount-min') as HTMLInputElement;
const max = () => screen.getByTestId('filter-amount-max') as HTMLInputElement;
const pressed = (testId: string) =>
    screen.getByTestId(testId).getAttribute('data-state') === 'on';

describe('AmountFilterFields', () => {
    it('defaults to Expense and turns 0 to 30 into the negative range', () => {
        const onChange = renderHarness();

        expect(pressed('filter-amount-expense')).toBe(true);

        fireEvent.change(min(), { target: { value: '0' } });
        fireEvent.change(max(), { target: { value: '30' } });

        expect(onChange).toHaveBeenLastCalledWith({
            amountMin: -30,
            amountMax: 0,
        });
        expect(min().value).toBe('0');
        expect(max().value).toBe('30');
    });

    it('keeps an empty min empty when only the max is typed', () => {
        const onChange = renderHarness();

        fireEvent.change(max(), { target: { value: '30' } });

        expect(onChange).toHaveBeenLastCalledWith({
            amountMin: -30,
            amountMax: 0,
        });
        expect(min().value).toBe('');
    });

    it('flips the range to positive when Income is picked', () => {
        const onChange = renderHarness();

        fireEvent.change(min(), { target: { value: '10' } });
        fireEvent.change(max(), { target: { value: '30' } });
        fireEvent.click(screen.getByTestId('filter-amount-income'));

        expect(onChange).toHaveBeenLastCalledWith({
            amountMin: 10,
            amountMax: 30,
        });
    });

    it('loads a signed expense range from a URL or saved filter as sizes', () => {
        renderHarness({ amountMin: -30, amountMax: -5 });

        expect(pressed('filter-amount-expense')).toBe(true);
        expect(min().value).toBe('5');
        expect(max().value).toBe('30');
    });

    it('reloads when the range is replaced from outside', () => {
        renderHarness({ amountMin: -30, amountMax: -5 });

        fireEvent.click(screen.getByText('Load saved'));

        expect(pressed('filter-amount-income')).toBe(true);
        expect(min().value).toBe('100');
        expect(max().value).toBe('');

        fireEvent.click(screen.getByText('Clear'));

        expect(pressed('filter-amount-income')).toBe(true);
        expect(min().value).toBe('');
        expect(max().value).toBe('');
    });

    it('shows a range spanning both sides as signed until a side is picked', () => {
        const onChange = renderHarness({ amountMin: -50, amountMax: 30 });

        expect(pressed('filter-amount-expense')).toBe(false);
        expect(pressed('filter-amount-income')).toBe(false);
        expect(min().value).toBe('-50');
        expect(
            screen.getByText(/covers both expenses and income/),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('filter-amount-expense'));

        expect(onChange).toHaveBeenLastCalledWith({
            amountMin: -50,
            amountMax: -30,
        });
    });
});
