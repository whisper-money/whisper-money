export type FieldType = 'string' | 'number';

export type Operator =
    | 'contains'
    | 'equals'
    | 'greater_than'
    | 'less_than'
    | 'is_empty'
    | 'is_not_empty';

export interface Condition {
    id: string;
    field: string;
    operator: Operator;
    value: string;
}

export interface ConditionGroup {
    id: string;
    operator: 'and' | 'or';
    conditions: Condition[];
}

export interface RuleStructure {
    groups: ConditionGroup[];
    groupOperator: 'and' | 'or';
}

export const FIELD_CONFIG: Record<
    string,
    { label: string; type: FieldType; operators: Operator[] }
> = {
    description: {
        label: 'Description',
        type: 'string',
        operators: ['contains', 'equals'],
    },
    amount: {
        label: 'Amount',
        type: 'number',
        operators: ['equals', 'greater_than', 'less_than'],
    },
    bank_name: {
        label: 'Bank Name',
        type: 'string',
        operators: ['contains', 'equals'],
    },
    creditor_name: {
        label: 'Creditor Name',
        type: 'string',
        operators: ['contains', 'equals', 'is_empty', 'is_not_empty'],
    },
    debtor_name: {
        label: 'Debtor Name',
        type: 'string',
        operators: ['contains', 'equals', 'is_empty', 'is_not_empty'],
    },
    account_name: {
        label: 'Account Name',
        type: 'string',
        operators: ['contains', 'equals'],
    },
};

export const OPERATOR_LABELS: Record<Operator, string> = {
    contains: 'contains',
    equals: 'equals',
    greater_than: 'greater than',
    less_than: 'less than',
    is_empty: 'is empty',
    is_not_empty: 'is not empty',
};

type JsonLogicRule = Record<string, unknown>;

function buildConditionJsonLogic(condition: Condition): JsonLogicRule {
    const { field, operator, value } = condition;

    switch (operator) {
        case 'contains':
            return { in: [value, { var: field }] };
        case 'equals':
            if (FIELD_CONFIG[field]?.type === 'number') {
                return { '==': [{ var: field }, parseFloat(value)] };
            }
            return { '==': [{ var: field }, value] };
        case 'greater_than':
            return { '>': [{ var: field }, parseFloat(value)] };
        case 'less_than':
            return { '<': [{ var: field }, parseFloat(value)] };
        case 'is_empty':
            return { '==': [{ var: field }, null] };
        case 'is_not_empty':
            return { '!=': [{ var: field }, null] };
        default:
            throw new Error(`Unknown operator: ${operator}`);
    }
}

function buildGroupJsonLogic(group: ConditionGroup): JsonLogicRule {
    if (group.conditions.length === 0) {
        return {};
    }

    if (group.conditions.length === 1) {
        return buildConditionJsonLogic(group.conditions[0]);
    }

    const conditions = group.conditions.map(buildConditionJsonLogic);
    return { [group.operator]: conditions };
}

export function buildJsonLogic(structure: RuleStructure): JsonLogicRule {
    const validGroups = structure.groups.filter(
        (group) => group.conditions.length > 0,
    );

    if (validGroups.length === 0) {
        return {};
    }

    if (validGroups.length === 1) {
        return buildGroupJsonLogic(validGroups[0]);
    }

    const groupLogics = validGroups.map(buildGroupJsonLogic);
    return { [structure.groupOperator]: groupLogics };
}

function jsonLogicArgs(value: unknown): unknown[] | null {
    return Array.isArray(value) ? value : null;
}

function jsonLogicVariable(value: unknown): string | null {
    if (value && typeof value === 'object' && 'var' in value) {
        return String(value.var);
    }

    return null;
}

function parseConditionFromJsonLogic(
    jsonLogic: JsonLogicRule,
): Condition | null {
    const id = crypto.randomUUID();

    if ('in' in jsonLogic) {
        const args = jsonLogicArgs(jsonLogic.in);
        const field = args ? jsonLogicVariable(args[1]) : null;

        if (args && field) {
            return {
                id,
                field,
                operator: 'contains',
                value: String(args[0]),
            };
        }
    }

    if ('==' in jsonLogic) {
        const args = jsonLogicArgs(jsonLogic['==']);
        const field = args ? jsonLogicVariable(args[0]) : null;

        if (args && field) {
            if (args[1] === null) {
                return {
                    id,
                    field,
                    operator: 'is_empty',
                    value: '',
                };
            }
            return {
                id,
                field,
                operator: 'equals',
                value: String(args[1]),
            };
        }
    }

    if ('!=' in jsonLogic) {
        const args = jsonLogicArgs(jsonLogic['!=']);
        const field = args ? jsonLogicVariable(args[0]) : null;

        if (args && field && args[1] === null) {
            return {
                id,
                field,
                operator: 'is_not_empty',
                value: '',
            };
        }
    }

    if ('>' in jsonLogic) {
        const args = jsonLogicArgs(jsonLogic['>']);
        const field = args ? jsonLogicVariable(args[0]) : null;

        if (args && field) {
            return {
                id,
                field,
                operator: 'greater_than',
                value: String(args[1]),
            };
        }
    }

    if ('<' in jsonLogic) {
        const args = jsonLogicArgs(jsonLogic['<']);
        const field = args ? jsonLogicVariable(args[0]) : null;

        if (args && field) {
            return {
                id,
                field,
                operator: 'less_than',
                value: String(args[1]),
            };
        }
    }

    return null;
}

export function parseJsonLogic(jsonLogic: JsonLogicRule): RuleStructure {
    const defaultStructure: RuleStructure = {
        groups: [
            {
                id: crypto.randomUUID(),
                operator: 'or',
                conditions: [],
            },
        ],
        groupOperator: 'or',
    };

    if (!jsonLogic || Object.keys(jsonLogic).length === 0) {
        return defaultStructure;
    }

    if ('and' in jsonLogic || 'or' in jsonLogic) {
        const operator = 'and' in jsonLogic ? 'and' : 'or';
        const items = jsonLogic[operator];

        if (!Array.isArray(items)) {
            return defaultStructure;
        }

        const hasNestedGroups = items.some(
            (item) =>
                typeof item === 'object' &&
                item !== null &&
                ('and' in item || 'or' in item),
        );

        if (hasNestedGroups) {
            const groups: ConditionGroup[] = [];

            for (const item of items) {
                if (
                    typeof item === 'object' &&
                    item !== null &&
                    ('and' in item || 'or' in item)
                ) {
                    const groupOp = 'and' in item ? 'and' : 'or';
                    const groupItems = item[groupOp];

                    if (Array.isArray(groupItems)) {
                        const conditions: Condition[] = [];
                        for (const condItem of groupItems) {
                            const parsed =
                                parseConditionFromJsonLogic(condItem);
                            if (parsed) {
                                conditions.push(parsed);
                            }
                        }

                        if (conditions.length > 0) {
                            groups.push({
                                id: crypto.randomUUID(),
                                operator: groupOp,
                                conditions,
                            });
                        }
                    }
                } else {
                    const parsed = parseConditionFromJsonLogic(item);
                    if (parsed) {
                        groups.push({
                            id: crypto.randomUUID(),
                            operator: 'and',
                            conditions: [parsed],
                        });
                    }
                }
            }

            return {
                groups: groups.length > 0 ? groups : defaultStructure.groups,
                groupOperator: operator,
            };
        } else {
            const conditions: Condition[] = [];
            for (const item of items) {
                const parsed = parseConditionFromJsonLogic(item);
                if (parsed) {
                    conditions.push(parsed);
                }
            }

            return {
                groups: [
                    {
                        id: crypto.randomUUID(),
                        operator,
                        conditions:
                            conditions.length > 0
                                ? conditions
                                : defaultStructure.groups[0].conditions,
                    },
                ],
                groupOperator: 'or',
            };
        }
    }

    const parsed = parseConditionFromJsonLogic(jsonLogic);
    if (parsed) {
        return {
            groups: [
                {
                    id: crypto.randomUUID(),
                    operator: 'or',
                    conditions: [parsed],
                },
            ],
            groupOperator: 'or',
        };
    }

    return defaultStructure;
}

export function createDescriptionCondition(description: string): Condition {
    return {
        id: crypto.randomUUID(),
        field: 'description',
        operator: 'contains',
        value: description.trim(),
    };
}

export function createEmptyCondition(): Condition {
    return createDescriptionCondition('');
}

export function createEmptyGroup(): ConditionGroup {
    return {
        id: crypto.randomUUID(),
        operator: 'or',
        conditions: [createEmptyCondition()],
    };
}

function cloneCondition(condition: Condition): Condition {
    return {
        ...condition,
        id: crypto.randomUUID(),
    };
}

export function addDescriptionMatchToRuleStructure(
    structure: RuleStructure,
    description: string,
): RuleStructure {
    const descriptionCondition = createDescriptionCondition(description);
    const descriptionGroup: ConditionGroup = {
        id: crypto.randomUUID(),
        operator: 'or',
        conditions: [descriptionCondition],
    };

    if (structure.groups.length === 0) {
        return {
            groups: [descriptionGroup],
            groupOperator: 'or',
        };
    }

    if (structure.groupOperator === 'or' || structure.groups.length === 1) {
        return {
            groups: [...structure.groups, descriptionGroup],
            groupOperator: 'or',
        };
    }

    return {
        groups: structure.groups.flatMap((group) => {
            const descriptionClone = cloneCondition(descriptionCondition);

            if (group.operator === 'or') {
                return [
                    {
                        ...group,
                        conditions: [...group.conditions, descriptionClone],
                    },
                ];
            }

            return group.conditions.map((condition) => ({
                id: crypto.randomUUID(),
                operator: 'or' as const,
                conditions: [
                    cloneCondition(condition),
                    cloneCondition(descriptionCondition),
                ],
            }));
        }),
        groupOperator: 'and',
    };
}

export function isValidRuleStructure(structure: RuleStructure): boolean {
    return structure.groups.some((group) =>
        group.conditions.some(
            (condition) =>
                condition.field &&
                condition.operator &&
                (condition.operator === 'is_empty' ||
                    condition.operator === 'is_not_empty' ||
                    condition.value.trim() !== ''),
        ),
    );
}
