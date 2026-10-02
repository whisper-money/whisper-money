export type FieldType = 'string' | 'number';

export type Operator =
    | 'contains'
    | 'not_contains'
    | 'equals'
    | 'not_equals'
    | 'greater_than'
    | 'less_than'
    | 'is_empty'
    | 'is_not_empty';

/**
 * Text operators every string field offers, in the order the builder lists them.
 * `not_contains` / `not_equals` are how a rule expresses an exception, and they
 * deliberately match a field that is empty or null — "the description does not
 * mention Visa" is true for a transaction with no description at all.
 */
const TEXT_OPERATORS: Operator[] = [
    'contains',
    'not_contains',
    'equals',
    'not_equals',
];

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
        operators: TEXT_OPERATORS,
    },
    amount: {
        label: 'Amount',
        type: 'number',
        operators: ['equals', 'not_equals', 'greater_than', 'less_than'],
    },
    bank_name: {
        label: 'Bank Name',
        type: 'string',
        operators: TEXT_OPERATORS,
    },
    creditor_name: {
        label: 'Creditor Name',
        type: 'string',
        operators: [...TEXT_OPERATORS, 'is_empty', 'is_not_empty'],
    },
    debtor_name: {
        label: 'Debtor Name',
        type: 'string',
        operators: [...TEXT_OPERATORS, 'is_empty', 'is_not_empty'],
    },
    account_name: {
        label: 'Account Name',
        type: 'string',
        operators: TEXT_OPERATORS,
    },
};

export const OPERATOR_LABELS: Record<Operator, string> = {
    contains: 'contains',
    not_contains: 'does not contain',
    equals: 'equals',
    not_equals: 'does not equal',
    greater_than: 'greater than',
    less_than: 'less than',
    is_empty: 'is empty',
    is_not_empty: 'is not empty',
};

type JsonLogicRule = Record<string, unknown>;

/**
 * The value an equality condition compares against. A numeric field compares as
 * a number, so `amount != 14` is stored as 14 and not as the string "14".
 */
function equalityValue(field: string, value: string): string | number {
    return FIELD_CONFIG[field]?.type === 'number' ? parseFloat(value) : value;
}

function buildConditionJsonLogic(condition: Condition): JsonLogicRule {
    const { field, operator, value } = condition;

    switch (operator) {
        case 'contains':
            return { in: [value, { var: field }] };
        case 'not_contains':
            return { '!': { in: [value, { var: field }] } };
        case 'not_equals':
            return { '!=': [{ var: field }, equalityValue(field, value)] };
        case 'equals':
            return { '==': [{ var: field }, equalityValue(field, value)] };
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

/**
 * Whether a condition has a value to compare against. A blank one is left out
 * of the rule instead of being saved as a comparison with null: a blank amount
 * would become `amount != null`, which matches every transaction.
 */
function isCompleteCondition(condition: Condition): boolean {
    if (!condition.field || !condition.operator) {
        return false;
    }

    if (
        condition.operator === 'is_empty' ||
        condition.operator === 'is_not_empty'
    ) {
        return true;
    }

    if (FIELD_CONFIG[condition.field]?.type === 'number') {
        return !Number.isNaN(parseFloat(condition.value));
    }

    return condition.value.trim() !== '';
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
    const validGroups = structure.groups
        .map((group) => ({
            ...group,
            conditions: group.conditions.filter(isCompleteCondition),
        }))
        .filter((group) => group.conditions.length > 0);

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

/**
 * What a condition becomes once it is wrapped in a JsonLogic `!`. Only `contains`
 * and `equals` have a negative twin in the builder, so anything else under a `!`
 * stays unparseable and is dropped like any other unknown node.
 *
 * The two entries are not symmetric: the builder writes `not_contains` as a `!`
 * and reads it back here, while it writes `not_equals` as a plain `!=`. The
 * `equals` entry only exists to also read the `!`-wrapped form an agent may
 * write over MCP.
 */
const NEGATED_OPERATORS: Partial<Record<Operator, Operator>> = {
    contains: 'not_contains',
    equals: 'not_equals',
};

function parseConditionFromJsonLogic(
    jsonLogic: JsonLogicRule,
): Condition | null {
    const id = crypto.randomUUID();

    if ('!' in jsonLogic) {
        // json-logic accepts both {"!": node} and {"!": [node]}.
        const negated = Array.isArray(jsonLogic['!'])
            ? jsonLogic['!'][0]
            : jsonLogic['!'];
        const inner =
            negated && typeof negated === 'object'
                ? parseConditionFromJsonLogic(negated as JsonLogicRule)
                : null;
        const operator = inner ? NEGATED_OPERATORS[inner.operator] : undefined;

        if (inner && operator) {
            return { ...inner, operator };
        }
    }

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

        if (args && field) {
            if (args[1] === null) {
                return {
                    id,
                    field,
                    operator: 'is_not_empty',
                    value: '',
                };
            }
            return {
                id,
                field,
                operator: 'not_equals',
                value: String(args[1]),
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

export type MoveDirection = 'up' | 'down';

/**
 * Swaps the item with `id` with its neighbour above or below, or returns the list
 * as is when it is already at that edge. Order never changes what a rule matches
 * (AND and OR do not care), it only keeps the builder tidy, and it survives a save
 * because `buildJsonLogic` and `parseJsonLogic` both keep array order.
 */
function moveById<T extends { id: string }>(
    items: T[],
    id: string,
    direction: MoveDirection,
): T[] {
    const from = items.findIndex((item) => item.id === id);
    const to = direction === 'up' ? from - 1 : from + 1;

    if (from === -1 || to < 0 || to >= items.length) {
        return items;
    }

    const moved = [...items];
    [moved[from], moved[to]] = [moved[to], moved[from]];

    return moved;
}

export function moveGroup(
    structure: RuleStructure,
    groupId: string,
    direction: MoveDirection,
): RuleStructure {
    return {
        ...structure,
        groups: moveById(structure.groups, groupId, direction),
    };
}

export function moveCondition(
    group: ConditionGroup,
    conditionId: string,
    direction: MoveDirection,
): ConditionGroup {
    return {
        ...group,
        conditions: moveById(group.conditions, conditionId, direction),
    };
}

export function reorderConditions(
    group: ConditionGroup,
    orderedIds: string[],
): ConditionGroup {
    const conditionsById = new Map(
        group.conditions.map((condition) => [condition.id, condition]),
    );

    const ordered = orderedIds
        .map((id) => conditionsById.get(id))
        .filter((condition): condition is Condition => !!condition);

    // A condition the order does not mention is kept at the end, never dropped.
    return {
        ...group,
        conditions: [
            ...ordered,
            ...group.conditions.filter(
                (condition) => !orderedIds.includes(condition.id),
            ),
        ],
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
        group.conditions.some(isCompleteCondition),
    );
}
