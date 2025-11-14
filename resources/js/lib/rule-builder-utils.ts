export type FieldType = 'string' | 'number' | 'date' | 'nullable';

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
    notes: {
        label: 'Notes',
        type: 'nullable',
        operators: ['contains', 'equals', 'is_empty', 'is_not_empty'],
    },
    amount: {
        label: 'Amount',
        type: 'number',
        operators: ['equals', 'greater_than', 'less_than'],
    },
    transaction_date: {
        label: 'Transaction Date',
        type: 'date',
        operators: ['equals'],
    },
    bank_name: {
        label: 'Bank Name',
        type: 'string',
        operators: ['contains', 'equals'],
    },
    account_name: {
        label: 'Account Name',
        type: 'string',
        operators: ['contains', 'equals'],
    },
    category: {
        label: 'Category',
        type: 'nullable',
        operators: ['equals', 'is_empty', 'is_not_empty'],
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

function buildConditionJsonLogic(condition: Condition): Record<string, any> {
    const { field, operator, value } = condition;

    switch (operator) {
        case 'contains':
            return { in: [value, { var: field }] };
        case 'equals':
            if (FIELD_CONFIG[field].type === 'number') {
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

function buildGroupJsonLogic(group: ConditionGroup): Record<string, any> {
    if (group.conditions.length === 0) {
        return {};
    }

    if (group.conditions.length === 1) {
        return buildConditionJsonLogic(group.conditions[0]);
    }

    const conditions = group.conditions.map(buildConditionJsonLogic);
    return { [group.operator]: conditions };
}

export function buildJsonLogic(structure: RuleStructure): Record<string, any> {
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

function parseConditionFromJsonLogic(
    jsonLogic: Record<string, any>,
): Condition | null {
    const id = crypto.randomUUID();

    if ('in' in jsonLogic) {
        const [value, varObj] = jsonLogic.in;
        if (varObj && typeof varObj === 'object' && 'var' in varObj) {
            return {
                id,
                field: varObj.var,
                operator: 'contains',
                value: String(value),
            };
        }
    }

    if ('==' in jsonLogic) {
        const [varObj, value] = jsonLogic['=='];
        if (varObj && typeof varObj === 'object' && 'var' in varObj) {
            if (value === null) {
                return {
                    id,
                    field: varObj.var,
                    operator: 'is_empty',
                    value: '',
                };
            }
            return {
                id,
                field: varObj.var,
                operator: 'equals',
                value: String(value),
            };
        }
    }

    if ('!=' in jsonLogic) {
        const [varObj, value] = jsonLogic['!='];
        if (
            varObj &&
            typeof varObj === 'object' &&
            'var' in varObj &&
            value === null
        ) {
            return {
                id,
                field: varObj.var,
                operator: 'is_not_empty',
                value: '',
            };
        }
    }

    if ('>' in jsonLogic) {
        const [varObj, value] = jsonLogic['>'];
        if (varObj && typeof varObj === 'object' && 'var' in varObj) {
            return {
                id,
                field: varObj.var,
                operator: 'greater_than',
                value: String(value),
            };
        }
    }

    if ('<' in jsonLogic) {
        const [varObj, value] = jsonLogic['<'];
        if (varObj && typeof varObj === 'object' && 'var' in varObj) {
            return {
                id,
                field: varObj.var,
                operator: 'less_than',
                value: String(value),
            };
        }
    }

    return null;
}

export function parseJsonLogic(jsonLogic: Record<string, any>): RuleStructure {
    const defaultStructure: RuleStructure = {
        groups: [
            {
                id: crypto.randomUUID(),
                operator: 'and',
                conditions: [],
            },
        ],
        groupOperator: 'and',
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
                groupOperator: 'and',
            };
        }
    }

    const parsed = parseConditionFromJsonLogic(jsonLogic);
    if (parsed) {
        return {
            groups: [
                {
                    id: crypto.randomUUID(),
                    operator: 'and',
                    conditions: [parsed],
                },
            ],
            groupOperator: 'and',
        };
    }

    return defaultStructure;
}

export function createEmptyCondition(): Condition {
    return {
        id: crypto.randomUUID(),
        field: 'description',
        operator: 'contains',
        value: '',
    };
}

export function createEmptyGroup(): ConditionGroup {
    return {
        id: crypto.randomUUID(),
        operator: 'and',
        conditions: [createEmptyCondition()],
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
