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
import {
    type Condition,
    type ConditionGroup,
    createEmptyCondition,
    createEmptyGroup,
    FIELD_CONFIG,
    OPERATOR_LABELS,
    type RuleStructure,
} from '@/lib/rule-builder-utils';
import { ChevronDown, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RuleBuilderProps {
    value: RuleStructure;
    onChange: (value: RuleStructure) => void;
    error?: string;
}

export function RuleBuilder({ value, onChange, error }: RuleBuilderProps) {
    const [structure, setStructure] = useState<RuleStructure>(value);

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

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <Label>Conditions</Label>
                {structure.groups.length > 1 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={toggleGroupOperator}
                        className="px-1.5 py-4"
                    >
                        Groups joined by:{' '}
                        <Badge variant="secondary" className="ml-2">
                            {structure.groupOperator.toUpperCase()}
                            <ChevronDown className="-mr-1 inline-block h-3 w-3" />
                        </Badge>
                    </Button>
                )}
            </div>

            <div className="space-y-4">
                {structure.groups.map((group, groupIndex) => (
                    <div key={group.id}>
                        <Card className="gap-2 p-4">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    {group.conditions.length > 1 && (
                                        <>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="px-1.5 py-4"
                                                size="sm"
                                                onClick={() => {
                                                    updateGroup(group.id, {
                                                        ...group,
                                                        operator:
                                                            group.operator ===
                                                            'and'
                                                                ? 'or'
                                                                : 'and',
                                                    });
                                                }}
                                            >
                                                <span className="text-sm">
                                                    Conditions joined by:{' '}
                                                </span>
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    {group.operator.toUpperCase()}
                                                    <ChevronDown className="-mr-1 inline-block h-3 w-3" />
                                                </Badge>
                                            </Button>
                                        </>
                                    )}
                                </div>
                                {structure.groups.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeGroup(group.id)}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>

                            <div className="space-y-2">
                                {group.conditions.map(
                                    (condition, conditionIndex) => (
                                        <div key={condition.id}>
                                            <ConditionRow
                                                condition={condition}
                                                onChange={(
                                                    updatedCondition,
                                                ) => {
                                                    updateGroup(group.id, {
                                                        ...group,
                                                        conditions:
                                                            group.conditions.map(
                                                                (c) =>
                                                                    c.id ===
                                                                    condition.id
                                                                        ? updatedCondition
                                                                        : c,
                                                            ),
                                                    });
                                                }}
                                                onRemove={() => {
                                                    if (
                                                        group.conditions
                                                            .length === 1
                                                    ) {
                                                        return;
                                                    }
                                                    updateGroup(group.id, {
                                                        ...group,
                                                        conditions:
                                                            group.conditions.filter(
                                                                (c) =>
                                                                    c.id !==
                                                                    condition.id,
                                                            ),
                                                    });
                                                }}
                                                canRemove={
                                                    group.conditions.length > 1
                                                }
                                            />
                                        </div>
                                    ),
                                )}
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-0 w-full"
                                onClick={() => {
                                    updateGroup(group.id, {
                                        ...group,
                                        conditions: [
                                            ...group.conditions,
                                            createEmptyCondition(),
                                        ],
                                    });
                                }}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Condition
                            </Button>
                        </Card>
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
                Add Group
            </Button>

            {error && <p className="text-sm text-red-500">{error}</p>}
        </div>
    );
}

interface ConditionRowProps {
    condition: Condition;
    onChange: (condition: Condition) => void;
    onRemove: () => void;
    canRemove: boolean;
}

function ConditionRow({
    condition,
    onChange,
    onRemove,
    canRemove,
}: ConditionRowProps) {
    const fieldConfig = FIELD_CONFIG[condition.field];
    const availableOperators = fieldConfig?.operators || [];

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

    const inputType =
        fieldConfig?.type === 'number'
            ? 'number'
            : fieldConfig?.type === 'date'
              ? 'date'
              : 'text';

    return (
        <div className="flex items-center gap-2">
            <Select value={condition.field} onValueChange={handleFieldChange}>
                <SelectTrigger className="w-[180px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries(FIELD_CONFIG).map(([key, config]) => (
                        <SelectItem key={key} value={key}>
                            {config.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select
                value={condition.operator}
                onValueChange={handleOperatorChange}
            >
                <SelectTrigger className="w-[140px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {availableOperators.map((op) => (
                        <SelectItem key={op} value={op}>
                            {OPERATOR_LABELS[op]}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {showValueInput && (
                <Input
                    type={inputType}
                    value={condition.value}
                    onChange={(e) =>
                        onChange({ ...condition, value: e.target.value })
                    }
                    placeholder="Value"
                    className="flex-1"
                    step={inputType === 'number' ? 'any' : undefined}
                />
            )}

            {!showValueInput && <div className="flex-1" />}

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onRemove}
                disabled={!canRemove}
            >
                <X className="h-4 w-4" />
            </Button>
        </div>
    );
}
