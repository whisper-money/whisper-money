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

describe('RuleBuilder amount field', () => {
    it('shows a stored negative amount, which is kept in currency units', () => {
        render(
            <RuleBuilder
                value={amountStructure('-21.99')}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByPlaceholderText('Value')).toHaveValue('-21.99');
    });

    it('stores what the user types in currency units, not in cents', () => {
        const onChange = vi.fn();
        render(<RuleBuilder value={amountStructure('')} onChange={onChange} />);

        const input = screen.getByPlaceholderText('Value');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '-5' } });
        fireEvent.blur(input);

        expect(amountValueOf(onChange.mock.lastCall![0])).toBe('-5');
    });

    it('leaves an untouched amount field empty instead of storing a zero', () => {
        const onChange = vi.fn();
        render(<RuleBuilder value={amountStructure('')} onChange={onChange} />);

        const input = screen.getByPlaceholderText('Value');
        fireEvent.focus(input);
        fireEvent.blur(input);

        expect(amountValueOf(onChange.mock.lastCall![0])).toBe('');
    });
});
