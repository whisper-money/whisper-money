import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { type RuleStructure } from '@/lib/rule-builder-utils';
import { RuleBuilder } from './rule-builder';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            locale: 'en-US',
            auth: { user: { currency_code: 'EUR' } },
        },
    }),
}));

function amountStructure(value: string): RuleStructure {
    return {
        groups: [
            {
                id: 'group-1',
                operator: 'and',
                conditions: [
                    {
                        id: 'condition-1',
                        field: 'amount',
                        operator: 'less_than',
                        value,
                    },
                ],
            },
        ],
        groupOperator: 'and',
    };
}

function amountValueOf(structure: RuleStructure): string {
    return structure.groups[0].conditions[0].value;
}

function typeAmount(typed: string): string {
    const onChange = vi.fn();
    render(<RuleBuilder value={amountStructure('')} onChange={onChange} />);

    const input = screen.getByPlaceholderText('Value');
    fireEvent.focus(input);
    if (typed !== '') {
        fireEvent.change(input, { target: { value: typed } });
    }
    fireEvent.blur(input);

    return amountValueOf(onChange.mock.lastCall![0]);
}

describe('RuleBuilder amount field', () => {
    it.each([
        ['-21.99', '-21.99'],
        ['1250', '1,250.00'],
    ])('shows a stored amount of %s', (stored, displayed) => {
        render(
            <RuleBuilder value={amountStructure(stored)} onChange={vi.fn()} />,
        );

        expect(screen.getByPlaceholderText('Value')).toHaveValue(displayed);
    });

    it.each([
        ['-5', '-5'],
        ['-21,99', '-21.99'],
        ['12.50', '12.5'],
    ])('stores %s in currency units, not in cents', (typed, stored) => {
        expect(typeAmount(typed)).toBe(stored);
    });

    // `amount < 0` is how you match every expense, so a typed zero has to
    // survive even though AmountInput reports it as 0 cents like an empty field.
    it('keeps a typed zero instead of blanking the condition', () => {
        expect(typeAmount('0')).toBe('0');
    });

    it('leaves an untouched amount field empty instead of storing a zero', () => {
        expect(typeAmount('')).toBe('');
    });
});
