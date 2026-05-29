import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CategoryCashflowDirection, CategoryType } from '@/types/category';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

const transferCashflowOptions: Array<{
    value: CategoryCashflowDirection;
    label: string;
    description: string;
}> = [
    {
        value: 'hidden',
        label: 'Do not show in cashflow chart',
        description: 'This transfer category stays out of the cashflow chart.',
    },
    {
        value: 'outflow',
        label: 'Show in cashflow chart as outflow',
        description:
            'Show this transfer category as money leaving available cash.',
    },
    {
        value: 'inflow',
        label: 'Show in cashflow chart as inflow',
        description:
            'Show this transfer category as money entering available cash.',
    },
];

interface CategoryCashflowDirectionFieldsProps {
    selectedType: CategoryType | '';
    defaultValue?: CategoryCashflowDirection;
}

function getTypeEffectDescription(selectedType: CategoryType | ''): string {
    switch (selectedType) {
        case 'income':
            return __(
                'Income categories count as cash inflow and appear in income charts. They do not appear in top spending categories.',
            );
        case 'expense':
            return __(
                'Expense categories count as cash outflow and appear in spending charts, including top spending categories.',
            );
        case 'transfer':
            return __(
                'Transfer categories are excluded from income, expenses, and top spending categories. Choose whether to show them in the cashflow chart.',
            );
        case 'savings':
            return __(
                'Savings categories appear as saved money at the top of cashflow and as cash outflow in the cashflow chart. They stay out of income, expenses, and top spending categories.',
            );
        case 'investment':
            return __(
                'Investment categories appear as invested money at the top of cashflow and as cash outflow in the cashflow chart. They stay out of income, expenses, and top spending categories.',
            );
        default:
            return __(
                'Choose a category type to see how it affects cashflow and charts.',
            );
    }
}

export function CategoryCashflowDirectionFields({
    selectedType,
    defaultValue = 'hidden',
}: CategoryCashflowDirectionFieldsProps) {
    const [cashflowDirection, setCashflowDirection] =
        useState<CategoryCashflowDirection>(defaultValue);
    const isTransfer = selectedType === 'transfer';
    const selectedOption = transferCashflowOptions.find(
        (option) => option.value === cashflowDirection,
    );

    return (
        <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-3">
            <div className="space-y-1">
                <Label htmlFor="cashflow_direction">
                    {__('Cashflow and charts')}
                </Label>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {getTypeEffectDescription(selectedType)}
                </p>
            </div>

            {!isTransfer && (
                <input
                    type="hidden"
                    name="cashflow_direction"
                    value={
                        selectedType === 'savings' ||
                        selectedType === 'investment'
                            ? 'outflow'
                            : 'hidden'
                    }
                />
            )}

            {isTransfer && (
                <>
                    <Select
                        name="cashflow_direction"
                        value={cashflowDirection}
                        onValueChange={(value) =>
                            setCashflowDirection(
                                value as CategoryCashflowDirection,
                            )
                        }
                        required
                    >
                        <SelectTrigger
                            id="cashflow_direction"
                            data-testid="cashflow-direction-trigger"
                        >
                            <SelectValue
                                placeholder={__(
                                    'Choose cashflow chart visibility',
                                )}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {transferCashflowOptions.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {__(option.label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {selectedOption && (
                        <p className="px-1 text-xs leading-relaxed text-muted-foreground">
                            {__(selectedOption.description)}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
