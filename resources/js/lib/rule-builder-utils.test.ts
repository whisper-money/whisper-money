import { describe, expect, it } from 'vitest';
import {
    addDescriptionMatchToRuleStructure,
    buildJsonLogic,
    createDescriptionCondition,
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
