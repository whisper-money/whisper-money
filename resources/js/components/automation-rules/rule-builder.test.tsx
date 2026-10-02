import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    type ConditionGroup,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
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

describe('RuleBuilder operators', () => {
    function descriptionStructure(): RuleStructure {
        return {
            groups: [
                {
                    id: 'group-1',
                    operator: 'and',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'description',
                            operator: 'not_contains',
                            value: 'tarjeta visa',
                        },
                    ],
                },
            ],
            groupOperator: 'and',
        };
    }

    it('shows the negative operator a saved rule was built with', () => {
        render(
            <RuleBuilder value={descriptionStructure()} onChange={vi.fn()} />,
        );

        expect(screen.getByText('does not contain')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Value')).toHaveValue(
            'tarjeta visa',
        );
    });
});

describe('RuleBuilder reordering', () => {
    function group(id: string, values: string[]): ConditionGroup {
        return {
            id,
            operator: 'or',
            conditions: values.map((value) => ({
                id: value,
                field: 'description',
                operator: 'contains',
                value,
            })),
        };
    }

    function renderBuilder(...groups: ConditionGroup[]) {
        const onChange = vi.fn();
        render(
            <RuleBuilder
                value={{ groupOperator: 'and', groups }}
                onChange={onChange}
            />,
        );

        return () => onChange.mock.lastCall![0] as RuleStructure;
    }

    it('adds nothing to a rule with one group and one condition', () => {
        renderBuilder(group('shops', ['Netflix']));

        expect(
            screen.queryByLabelText('Drag to reorder'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^Move / }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Group 1')).not.toBeInTheDocument();
        expect(screen.queryByText('Condition 1')).not.toBeInTheDocument();
    });

    it('moves a group one step with its arrows and announces where it landed', () => {
        const lastChange = renderBuilder(
            group('shops', ['Lidl']),
            group('accounts', ['Revolut']),
            group('amounts', ['Nómina']),
        );

        expect(screen.getByText('Group 1')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Move group 1 up' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Move group 3 down' }),
        ).toBeDisabled();

        fireEvent.click(
            screen.getByRole('button', { name: 'Move group 3 up' }),
        );

        expect(lastChange().groups.map((g) => g.id)).toEqual([
            'shops',
            'amounts',
            'accounts',
        ]);
        expect(
            screen.getByText('Group moved to position 2 of 3'),
        ).toBeInTheDocument();
    });

    it('gives each condition a drag handle and phone arrows once its group has several', () => {
        const lastChange = renderBuilder(group('shops', ['Mercadona', 'Lidl']));

        expect(screen.getAllByLabelText('Drag to reorder')).toHaveLength(2);
        expect(screen.getByText('Condition 2')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /group/ }),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Move condition 2 up' }),
        );

        expect(lastChange().groups[0].conditions.map((c) => c.value)).toEqual([
            'Lidl',
            'Mercadona',
        ]);
        expect(
            screen.getByText('Condition moved to position 1 of 2'),
        ).toBeInTheDocument();
    });
});
