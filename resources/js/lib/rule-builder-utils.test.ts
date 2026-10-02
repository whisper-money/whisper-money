import { describe, expect, it } from 'vitest';
import {
    addDescriptionMatchToRuleStructure,
    buildJsonLogic,
    type Condition,
    createDescriptionCondition,
    FIELD_CONFIG,
    isValidRuleStructure,
    type Operator,
    parseJsonLogic,
    type RuleStructure,
} from './rule-builder-utils';

describe('createDescriptionCondition', () => {
    it('creates a contains condition for transaction descriptions', () => {
        expect(createDescriptionCondition('  Toast Coffee  ')).toMatchObject({
            field: 'description',
            operator: 'contains',
            value: 'Toast Coffee',
        });
    });
});

describe('buildJsonLogic', () => {
    it('builds conditions for counterparty fields', () => {
        const structure: RuleStructure = {
            groupOperator: 'or',
            groups: [
                {
                    id: 'group-1',
                    operator: 'and',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'creditor_name',
                            operator: 'contains',
                            value: 'amazon',
                        },
                        {
                            id: 'condition-2',
                            field: 'debtor_name',
                            operator: 'is_not_empty',
                            value: '',
                        },
                    ],
                },
            ],
        };

        expect(buildJsonLogic(structure)).toMatchObject({
            and: [
                { in: ['amazon', { var: 'creditor_name' }] },
                { '!=': [{ var: 'debtor_name' }, null] },
            ],
        });
    });
});

describe('addDescriptionMatchToRuleStructure', () => {
    it('adds a new description group to a simple rule', () => {
        const structure: RuleStructure = {
            groupOperator: 'or',
            groups: [
                {
                    id: 'group-1',
                    operator: 'or',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'bank_name',
                            operator: 'contains',
                            value: 'bank',
                        },
                    ],
                },
            ],
        };

        const updated = addDescriptionMatchToRuleStructure(
            structure,
            'Toast Coffee',
        );

        expect(updated.groupOperator).toBe('or');
        expect(updated.groups).toHaveLength(2);
        expect(updated.groups[1].conditions[0]).toMatchObject({
            field: 'description',
            operator: 'contains',
            value: 'Toast Coffee',
        });
        expect(buildJsonLogic(updated)).toMatchObject({
            or: [
                { in: ['bank', { var: 'bank_name' }] },
                { in: ['Toast Coffee', { var: 'description' }] },
            ],
        });
    });

    it('preserves top-level AND semantics when adding a description match', () => {
        const structure: RuleStructure = {
            groupOperator: 'and',
            groups: [
                {
                    id: 'group-1',
                    operator: 'and',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'bank_name',
                            operator: 'contains',
                            value: 'bank',
                        },
                        {
                            id: 'condition-2',
                            field: 'amount',
                            operator: 'less_than',
                            value: '0',
                        },
                    ],
                },
                {
                    id: 'group-2',
                    operator: 'or',
                    conditions: [
                        {
                            id: 'condition-3',
                            field: 'account_name',
                            operator: 'contains',
                            value: 'checking',
                        },
                    ],
                },
            ],
        };

        const updated = addDescriptionMatchToRuleStructure(
            structure,
            'Toast Coffee',
        );
        const jsonLogic = buildJsonLogic(updated);

        expect(updated.groupOperator).toBe('and');
        expect(updated.groups).toHaveLength(3);
        expect(jsonLogic).toMatchObject({
            and: [
                {
                    or: [
                        { in: ['bank', { var: 'bank_name' }] },
                        { in: ['Toast Coffee', { var: 'description' }] },
                    ],
                },
                {
                    or: [
                        { '<': [{ var: 'amount' }, 0] },
                        { in: ['Toast Coffee', { var: 'description' }] },
                    ],
                },
                {
                    or: [
                        { in: ['checking', { var: 'account_name' }] },
                        { in: ['Toast Coffee', { var: 'description' }] },
                    ],
                },
            ],
        });
    });
});

function singleCondition(
    field: string,
    operator: Operator,
    value: string,
): RuleStructure {
    return {
        groupOperator: 'and',
        groups: [
            {
                id: 'group-1',
                operator: 'and',
                conditions: [{ id: 'condition-1', field, operator, value }],
            },
        ],
    };
}

function firstCondition(structure: RuleStructure): Condition {
    return structure.groups[0].conditions[0];
}

describe('negative text operators', () => {
    it.each([
        [
            'not_contains' as Operator,
            { '!': { in: ['tarjeta visa', { var: 'description' }] } },
        ],
        [
            'not_equals' as Operator,
            { '!=': [{ var: 'description' }, 'tarjeta visa'] },
        ],
    ])('builds %s into JsonLogic', (operator, expected) => {
        expect(
            buildJsonLogic(
                singleCondition('description', operator, 'tarjeta visa'),
            ),
        ).toEqual(expected);
    });

    it.each<Operator>(['contains', 'not_contains', 'equals', 'not_equals'])(
        'round-trips a %s condition through build and parse',
        (operator) => {
            const built = buildJsonLogic(
                singleCondition('creditor_name', operator, 'tarjeta visa'),
            );

            expect(firstCondition(parseJsonLogic(built))).toMatchObject({
                field: 'creditor_name',
                operator,
                value: 'tarjeta visa',
            });
        },
    );

    // The exception the operators exist for: "contains X but not Y", which only
    // works if the AND group survives the trip back through parseJsonLogic.
    it('round-trips an exception rule', () => {
        const structure: RuleStructure = {
            groupOperator: 'and',
            groups: [
                {
                    id: 'group-1',
                    operator: 'and',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'description',
                            operator: 'contains',
                            value: 'palabra clave',
                        },
                        {
                            id: 'condition-2',
                            field: 'description',
                            operator: 'not_contains',
                            value: 'tarjeta visa',
                        },
                    ],
                },
            ],
        };

        const jsonLogic = buildJsonLogic(structure);

        expect(jsonLogic).toEqual({
            and: [
                { in: ['palabra clave', { var: 'description' }] },
                { '!': { in: ['tarjeta visa', { var: 'description' }] } },
            ],
        });

        const parsed = parseJsonLogic(jsonLogic);

        expect(parsed.groups[0].operator).toBe('and');
        expect(parsed.groups[0].conditions).toMatchObject([
            {
                field: 'description',
                operator: 'contains',
                value: 'palabra clave',
            },
            {
                field: 'description',
                operator: 'not_contains',
                value: 'tarjeta visa',
            },
        ]);
    });

    // json-logic accepts both {"!": node} and {"!": [node]}, and an agent over
    // MCP can save either, so the builder has to reopen both.
    it('parses the array form of a negation', () => {
        const parsed = parseJsonLogic({
            '!': [{ in: ['tarjeta visa', { var: 'description' }] }],
        });

        expect(firstCondition(parsed)).toMatchObject({
            field: 'description',
            operator: 'not_contains',
            value: 'tarjeta visa',
        });
    });

    // `is_not_empty` is also a `!=`, and it must keep winning over not_equals.
    it('still parses a null comparison as is not empty', () => {
        const parsed = parseJsonLogic({
            '!=': [{ var: 'creditor_name' }, null],
        });

        expect(firstCondition(parsed)).toMatchObject({
            field: 'creditor_name',
            operator: 'is_not_empty',
            value: '',
        });
    });
});

describe('amount equality operators', () => {
    it('offers does not equal right after equals', () => {
        expect(FIELD_CONFIG.amount.operators).toEqual([
            'equals',
            'not_equals',
            'greater_than',
            'less_than',
        ]);
    });

    // Stored as a number, like `equals`, so the rule compares amounts and not
    // the amount against the string "-14".
    it.each([
        ['equals' as Operator, { '==': [{ var: 'amount' }, -14] }],
        ['not_equals' as Operator, { '!=': [{ var: 'amount' }, -14] }],
    ])('builds an amount %s with a numeric value', (operator, expected) => {
        expect(
            buildJsonLogic(singleCondition('amount', operator, '-14')),
        ).toEqual(expected);
    });

    it.each(['-14', '-21.99', '14'])(
        'round-trips an amount not_equals of %s through build and parse',
        (value) => {
            const built = buildJsonLogic(
                singleCondition('amount', 'not_equals', value),
            );

            expect(firstCondition(parseJsonLogic(built))).toMatchObject({
                field: 'amount',
                operator: 'not_equals',
                value,
            });
        },
    );

    // The rule from the request: every GitHub charge except the subscription.
    it('round-trips a description match with an amount exception', () => {
        const structure: RuleStructure = {
            groupOperator: 'and',
            groups: [
                {
                    id: 'group-1',
                    operator: 'and',
                    conditions: [
                        {
                            id: 'condition-1',
                            field: 'description',
                            operator: 'contains',
                            value: 'github',
                        },
                        {
                            id: 'condition-2',
                            field: 'amount',
                            operator: 'not_equals',
                            value: '-14',
                        },
                    ],
                },
            ],
        };

        const jsonLogic = buildJsonLogic(structure);

        expect(jsonLogic).toEqual({
            and: [
                { in: ['github', { var: 'description' }] },
                { '!=': [{ var: 'amount' }, -14] },
            ],
        });
        expect(parseJsonLogic(jsonLogic).groups[0].conditions).toMatchObject([
            { field: 'description', operator: 'contains', value: 'github' },
            { field: 'amount', operator: 'not_equals', value: '-14' },
        ]);
    });

    // A cleared amount would otherwise be saved as `amount != null`, which is
    // also how `is_not_empty` is written, and match every transaction.
    it.each<Operator>(['equals', 'not_equals', 'greater_than', 'less_than'])(
        'leaves a blank amount %s out of the rule',
        (operator) => {
            const structure: RuleStructure = {
                groupOperator: 'and',
                groups: [
                    {
                        id: 'group-1',
                        operator: 'and',
                        conditions: [
                            {
                                id: 'condition-1',
                                field: 'description',
                                operator: 'contains',
                                value: 'github',
                            },
                            {
                                id: 'condition-2',
                                field: 'amount',
                                operator,
                                value: '',
                            },
                        ],
                    },
                ],
            };

            expect(buildJsonLogic(structure)).toEqual({
                in: ['github', { var: 'description' }],
            });
        },
    );

    it('does not count a blank amount as a valid condition', () => {
        expect(
            isValidRuleStructure(singleCondition('amount', 'not_equals', '')),
        ).toBe(false);
    });

    // An agent over MCP may negate an equality instead of writing `!=`.
    it('parses a negated amount equality as does not equal', () => {
        const parsed = parseJsonLogic({
            '!': { '==': [{ var: 'amount' }, -14] },
        });

        expect(firstCondition(parsed)).toMatchObject({
            field: 'amount',
            operator: 'not_equals',
            value: '-14',
        });
    });
});
