import { ReorderActions } from '@/components/automation-rules/reorder-actions';
import InputError from '@/components/input-error';
import { SortableGrid } from '@/components/sortable-grid';
import { AmountInput } from '@/components/ui/amount-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAnchoredReorder } from '@/hooks/use-anchored-reorder';
import {
    type Condition,
    type ConditionGroup,
    createEmptyCondition,
    createEmptyGroup,
    FIELD_CONFIG,
    moveCondition,
    type MoveDirection,
    moveGroup,
    OPERATOR_LABELS,
    reorderConditions,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { toMajorUnits, toMinorUnits } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import type {
    Announcements,
    ScreenReaderInstructions,
    UniqueIdentifier,
} from '@dnd-kit/core';
import { usePage } from '@inertiajs/react';
import { ChevronDown, Plus, Trash2, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

interface RuleBuilderProps {
    value: RuleStructure;
    onChange: (value: RuleStructure) => void;
    error?: string;
}

/** `data-reorder-list` of the groups; each group's conditions use the group id. */
const GROUPS_LIST = 'groups';

/** 1-based position of `id` in `items` and the item count, for labels and announcements. */
function positionOf(
    items: { id: string }[],
    id: UniqueIdentifier,
): { position: number; total: number } {
    return {
        position: items.findIndex((item) => item.id === id) + 1,
        total: items.length,
    };
}

export function RuleBuilder({ value, onChange, error }: RuleBuilderProps) {
    const [structure, setStructure] = useState<RuleStructure>(value);
    const { containerRef, anchor, highlightedId, announcement } =
        useAnchoredReorder<HTMLDivElement>();

    useEffect(() => {
        setStructure(value);
    }, [value]);

    const handleStructureChange = (newStructure: RuleStructure) => {
        setStructure(newStructure);
        onChange(newStructure);
    };

    const addGroup = () => {
        handleStructureChange({
            ...structure,
            groups: [...structure.groups, createEmptyGroup()],
        });
    };

    const removeGroup = (groupId: string) => {
        if (structure.groups.length === 1) {
            return;
        }
        handleStructureChange({
            ...structure,
            groups: structure.groups.filter((g) => g.id !== groupId),
        });
    };

    const updateGroup = (groupId: string, updatedGroup: ConditionGroup) => {
        handleStructureChange({
            ...structure,
            groups: structure.groups.map((g) =>
                g.id === groupId ? updatedGroup : g,
            ),
        });
    };

    const toggleGroupOperator = () => {
        handleStructureChange({
            ...structure,
            groupOperator: structure.groupOperator === 'and' ? 'or' : 'and',
        });
    };

    const moveGroupOneStep = (groupId: string, direction: MoveDirection) => {
        const moved = moveGroup(structure, groupId, direction);

        anchor({
            list: GROUPS_LIST,
            id: groupId,
            direction,
            announcement: __(
                'Group moved to position :position of :total',
                positionOf(moved.groups, groupId),
            ),
        });
        handleStructureChange(moved);
    };

    const moveConditionOneStep = (
        group: ConditionGroup,
        conditionId: string,
        direction: MoveDirection,
    ) => {
        const moved = moveCondition(group, conditionId, direction);

        anchor({
            list: group.id,
            id: conditionId,
            direction,
            announcement: __(
                'Condition moved to position :position of :total',
                positionOf(moved.conditions, conditionId),
            ),
        });
        updateGroup(group.id, moved);
    };

    return (
        <div ref={containerRef} className="space-y-4">
            <div className="flex items-center justify-between">
                <Label>{__('Conditions')}</Label>
                {structure.groups.length > 1 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={toggleGroupOperator}
                        className="px-1.5 py-4"
                    >
                        {__('Groups joined by:')}{' '}
                        <Badge variant="secondary" className="ml-2">
                            {structure.groupOperator.toUpperCase()}
                            <ChevronDown className="-mr-1 inline-block h-3 w-3" />
                        </Badge>
                    </Button>
                )}
            </div>

            <div className="space-y-4">
                {structure.groups.map((group, index) => (
                    <div
                        key={group.id}
                        data-reorder-list={GROUPS_LIST}
                        data-reorder-id={group.id}
                    >
                        <ConditionGroupCard
                            group={group}
                            position={index + 1}
                            totalGroups={structure.groups.length}
                            highlightedId={highlightedId}
                            onChange={(updatedGroup) =>
                                updateGroup(group.id, updatedGroup)
                            }
                            onRemove={() => removeGroup(group.id)}
                            onMove={(direction) =>
                                moveGroupOneStep(group.id, direction)
                            }
                            onMoveCondition={(conditionId, direction) =>
                                moveConditionOneStep(
                                    group,
                                    conditionId,
                                    direction,
                                )
                            }
                        />
                    </div>
                ))}
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-full"
                onClick={addGroup}
            >
                <Plus className="mr-2 h-4 w-4" />
                {__('Add Group')}
            </Button>

            <InputError message={error} />

            <p aria-live="polite" className="sr-only">
                {announcement}
            </p>
        </div>
    );
}

interface ConditionGroupCardProps {
    group: ConditionGroup;
    /** 1-based place among the groups. */
    position: number;
    totalGroups: number;
    /** The group or condition just moved with the arrows, briefly ringed. */
    highlightedId: string | null;
    onChange: (group: ConditionGroup) => void;
    onRemove: () => void;
    onMove: (direction: MoveDirection) => void;
    onMoveCondition: (conditionId: string, direction: MoveDirection) => void;
}

/**
 * One group of conditions. With several groups it gains ↑ ↓ to reorder them, next
 * to the delete button; on phones that row is titled "Group N" so the arrows read
 * as moving the whole group. Its conditions reorder by dragging their handle on
 * larger screens and with their own arrows on phones.
 */
function ConditionGroupCard({
    group,
    position,
    totalGroups,
    highlightedId,
    onChange,
    onRemove,
    onMove,
    onMoveCondition,
}: ConditionGroupCardProps) {
    const hasSeveralGroups = totalGroups > 1;
    const hasSeveralConditions = group.conditions.length > 1;

    const updateCondition = (updatedCondition: Condition) => {
        onChange({
            ...group,
            conditions: group.conditions.map((c) =>
                c.id === updatedCondition.id ? updatedCondition : c,
            ),
        });
    };

    const removeCondition = (conditionId: string) => {
        if (!hasSeveralConditions) {
            return;
        }
        onChange({
            ...group,
            conditions: group.conditions.filter((c) => c.id !== conditionId),
        });
    };

    return (
        <Card
            className={cn(
                'gap-2 p-4 transition-shadow duration-500',
                hasSeveralGroups && 'max-sm:pt-3',
                highlightedId === group.id && 'ring-2 ring-foreground/45',
            )}
        >
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                {hasSeveralGroups && (
                    <div className="flex h-10 items-center justify-between gap-2 sm:order-last sm:ml-auto sm:h-auto">
                        <span className="text-sm font-semibold sm:hidden">
                            {__('Group :number', { number: position })}
                        </span>
                        <ReorderActions
                            itemId={group.id}
                            canMoveUp={position > 1}
                            canMoveDown={position < totalGroups}
                            onMove={onMove}
                            labels={{
                                up: __('Move group :number up', {
                                    number: position,
                                }),
                                down: __('Move group :number down', {
                                    number: position,
                                }),
                                remove: __('Delete group :number', {
                                    number: position,
                                }),
                            }}
                            tooltips={{
                                up: __('Move group up'),
                                down: __('Move group down'),
                            }}
                            removeIcon={<Trash2 />}
                            onRemove={onRemove}
                        />
                    </div>
                )}
                {hasSeveralConditions && (
                    <Button
                        type="button"
                        variant="outline"
                        className="self-start px-1.5 py-4 sm:self-auto"
                        size="sm"
                        onClick={() => {
                            onChange({
                                ...group,
                                operator:
                                    group.operator === 'and' ? 'or' : 'and',
                            });
                        }}
                        data-testid="toggle-condition-operator"
                    >
                        <span className="text-sm">
                            {__('Conditions joined by:')}{' '}
                        </span>
                        <Badge variant="secondary" className="ml-2">
                            {group.operator.toUpperCase()}
                            <ChevronDown className="-mr-1 inline-block h-3 w-3" />
                        </Badge>
                    </Button>
                )}
            </div>

            <SortableGrid
                layout="list"
                className="flex flex-col gap-2"
                items={group.conditions}
                getId={(condition) => condition.id}
                onReorder={(orderedIds) =>
                    onChange(reorderConditions(group, orderedIds))
                }
                handleClassName="hidden h-9 w-6 shrink-0 items-center justify-center rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[dragging]:bg-muted data-[dragging]:text-foreground sm:inline-flex [&_svg]:size-4"
                draggingClassName="-m-1.5 rounded-[10px] bg-card p-1.5 shadow-[0_12px_28px_-8px_rgba(0,0,0,0.25),0_2px_6px_rgba(0,0,0,0.08)] ring-1 ring-border dark:shadow-[0_12px_28px_-8px_rgba(0,0,0,0.75),0_2px_6px_rgba(0,0,0,0.5)]"
                accessibility={conditionDragAccessibility(group.conditions)}
                renderItem={(condition, dragHandle) => (
                    <ConditionRow
                        listId={group.id}
                        condition={condition}
                        position={
                            positionOf(group.conditions, condition.id).position
                        }
                        total={group.conditions.length}
                        dragHandle={hasSeveralConditions ? dragHandle : null}
                        highlighted={highlightedId === condition.id}
                        onChange={updateCondition}
                        onRemove={() => removeCondition(condition.id)}
                        onMove={(direction) =>
                            onMoveCondition(condition.id, direction)
                        }
                    />
                )}
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-0 w-full"
                onClick={() => {
                    onChange({
                        ...group,
                        conditions: [
                            ...group.conditions,
                            createEmptyCondition(),
                        ],
                    });
                }}
            >
                <Plus className="mr-2 h-4 w-4" />
                {__('Add Condition')}
            </Button>
        </Card>
    );
}

/** Screen reader texts for dragging a condition, by position since ids are UUIDs. */
function conditionDragAccessibility(conditions: Condition[]): {
    announcements: Announcements;
    screenReaderInstructions: ScreenReaderInstructions;
} {
    return {
        screenReaderInstructions: {
            draggable: __(
                'To pick up a condition, press Space or Enter. Use the up and down arrow keys to move it, Space or Enter to drop it, and Escape to cancel.',
            ),
        },
        announcements: {
            onDragStart: ({ active }) =>
                __(
                    'Picked up condition :position of :total',
                    positionOf(conditions, active.id),
                ),
            onDragOver: ({ over }) =>
                over
                    ? __(
                          'Condition moved to position :position of :total',
                          positionOf(conditions, over.id),
                      )
                    : undefined,
            onDragEnd: ({ over }) =>
                over
                    ? __(
                          'Condition dropped at position :position of :total',
                          positionOf(conditions, over.id),
                      )
                    : undefined,
            onDragCancel: ({ active }) =>
                __(
                    'Move cancelled. Condition back at position :position of :total',
                    positionOf(conditions, active.id),
                ),
        },
    };
}

/**
 * `condition.value` is stored as a string in major units ("-21.99") because that
 * is what `buildJsonLogic` and the backend expect, while `AmountInput` speaks
 * minor units. Storing the threshold in major units is why existing rules keep
 * their meaning across a currency rescale.
 */
function majorUnitsToMinor(value: string, currencyCode: string): number {
    const parsed = parseFloat(value);

    return Number.isNaN(parsed) ? 0 : toMinorUnits(parsed, currencyCode);
}

interface ConditionRowProps {
    /** `data-reorder-list` shared with the other conditions of its group. */
    listId: string;
    condition: Condition;
    /** 1-based place in its group. */
    position: number;
    total: number;
    /** Only passed once the group has more than one condition. */
    dragHandle: ReactNode;
    highlighted: boolean;
    onChange: (condition: Condition) => void;
    onRemove: () => void;
    onMove: (direction: MoveDirection) => void;
}

/**
 * One condition: a single row on larger screens, stacked fields on phones. Once
 * its group has more than one condition, a phone shows it in its own box titled
 * "Condition N" with ↑ ↓ and delete, so it is clear what each arrow moves.
 */
function ConditionRow({
    listId,
    condition,
    position,
    total,
    dragHandle,
    highlighted,
    onChange,
    onRemove,
    onMove,
}: ConditionRowProps) {
    const fieldConfig = FIELD_CONFIG[condition.field];
    const availableOperators = fieldConfig?.operators || [];
    const currencyCode = usePage<SharedData>().props.auth.user.currency_code;
    const isReorderable = total > 1;
    const boxedFieldClassName = isReorderable && 'max-sm:bg-background';

    const handleFieldChange = (field: string) => {
        const newFieldConfig = FIELD_CONFIG[field];
        const newOperator = newFieldConfig.operators[0];
        onChange({
            ...condition,
            field,
            operator: newOperator,
            value: '',
        });
    };

    const handleOperatorChange = (operator: string) => {
        onChange({
            ...condition,
            operator: operator as Condition['operator'],
            value:
                operator === 'is_empty' || operator === 'is_not_empty'
                    ? ''
                    : condition.value,
        });
    };

    const showValueInput =
        condition.operator !== 'is_empty' &&
        condition.operator !== 'is_not_empty';

    const isAmountField = fieldConfig?.type === 'number';

    const showAmountHint = showValueInput && isAmountField;

    const removeLabel = __('Delete condition :number', { number: position });

    return (
        <div
            data-reorder-list={listId}
            data-reorder-id={condition.id}
            className={cn(
                'flex flex-col gap-1',
                isReorderable &&
                    'max-sm:gap-2 max-sm:rounded-[10px] max-sm:bg-muted max-sm:px-3 max-sm:pt-0.5 max-sm:pb-3 max-sm:transition-shadow max-sm:duration-500',
                isReorderable &&
                    highlighted &&
                    'max-sm:ring-2 max-sm:ring-foreground/45',
            )}
        >
            {isReorderable && (
                <div className="flex h-10 items-center justify-between sm:hidden">
                    <span
                        className={cn(
                            'text-xs font-semibold text-muted-foreground',
                            highlighted && 'text-foreground',
                        )}
                    >
                        {__('Condition :number', { number: position })}
                    </span>
                    <ReorderActions
                        itemId={condition.id}
                        canMoveUp={position > 1}
                        canMoveDown={position < total}
                        onMove={onMove}
                        labels={{
                            up: __('Move condition :number up', {
                                number: position,
                            }),
                            down: __('Move condition :number down', {
                                number: position,
                            }),
                            remove: removeLabel,
                        }}
                        removeIcon={<X />}
                        onRemove={onRemove}
                    />
                </div>
            )}

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                {dragHandle}

                <Select
                    value={condition.field}
                    onValueChange={handleFieldChange}
                >
                    <SelectTrigger
                        className={cn(
                            'w-full sm:w-[180px]',
                            boxedFieldClassName,
                        )}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(FIELD_CONFIG).map(([key, config]) => (
                            <SelectItem key={key} value={key}>
                                {__(config.label)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={condition.operator}
                    onValueChange={handleOperatorChange}
                >
                    {/* Wide enough for the longest label ("does not contain"),
                        which the trigger would otherwise clamp to one clipped line. */}
                    <SelectTrigger
                        className={cn(
                            'w-full sm:w-[170px]',
                            boxedFieldClassName,
                        )}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {availableOperators.map((op) => (
                            <SelectItem key={op} value={op}>
                                {__(OPERATOR_LABELS[op])}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {!showValueInput ? (
                    <div className="hidden sm:flex sm:flex-1" />
                ) : isAmountField ? (
                    <div className="w-full sm:flex-1">
                        <AmountInput
                            value={majorUnitsToMinor(
                                condition.value,
                                currencyCode,
                            )}
                            onChange={(valueInCents, isEmpty) =>
                                onChange({
                                    ...condition,
                                    // A cleared field and a typed 0 both resolve
                                    // to 0; only the cleared one may blank
                                    // the condition, or `amount < 0` would be
                                    // impossible to express.
                                    value: isEmpty
                                        ? ''
                                        : String(
                                              toMajorUnits(
                                                  valueInCents,
                                                  currencyCode,
                                              ),
                                          ),
                                })
                            }
                            currencyCode={currencyCode}
                            placeholder={__('Value')}
                            allowNegative
                        />
                    </div>
                ) : (
                    <Input
                        value={condition.value}
                        onChange={(e) =>
                            onChange({ ...condition, value: e.target.value })
                        }
                        placeholder={__('Value')}
                        className={cn('w-full sm:flex-1', boxedFieldClassName)}
                    />
                )}

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    onClick={onRemove}
                    disabled={!isReorderable}
                    aria-label={removeLabel}
                    className={cn(
                        'self-end sm:self-auto',
                        isReorderable && 'max-sm:hidden',
                    )}
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {showAmountHint && (
                <p
                    className={cn(
                        'py-2 pl-2 text-xs text-muted-foreground',
                        isReorderable && 'max-sm:py-0 max-sm:pl-1',
                    )}
                >
                    {__(
                        'Use a negative value for expenses (e.g. -21.99) and a positive value for income.',
                    )}
                </p>
            )}
        </div>
    );
}
