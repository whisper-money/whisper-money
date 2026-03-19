import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Info } from 'lucide-react';
import { useState } from 'react';

const cashflowDirectionOptions: Array<{
    value: CategoryCashflowDirection;
    label: string;
    description: string;
}> = [
    {
        value: 'hidden',
        label: __('Do not show'),
        description: __('Keep this transfer out of cashflow analytics.'),
    },
    {
        value: 'outflow',
        label: __('Show as cash outflow'),
        description: __(
            'Track money leaving your available cash, like investments or savings transfers.',
        ),
    },
    {
        value: 'inflow',
        label: __('Show as cash inflow'),
        description: __(
            'Track money entering your available cash without counting it as income.',
        ),
    },
];

interface CategoryCashflowDirectionFieldsProps {
    selectedType: CategoryType | '';
    defaultValue?: CategoryCashflowDirection;
}

export function CategoryCashflowDirectionFields({
    selectedType,
    defaultValue = 'hidden',
}: CategoryCashflowDirectionFieldsProps) {
    const [cashflowDirection, setCashflowDirection] =
        useState<CategoryCashflowDirection>(defaultValue);
    const selectedOption = cashflowDirectionOptions.find(
        (option) => option.value === cashflowDirection,
    );

    if (selectedType !== 'transfer') {
        return null;
    }

    return (
        <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-3">
            <div className="space-y-1">
                <Label htmlFor="cashflow_direction">
                    {__('Cashflow analytics')}
                </Label>
                <p className="text-xs text-muted-foreground">
                    {__(
                        'Transfer categories stay out of income and expense totals, but you can still track them in the money flow chart.',
                    )}
                </p>
            </div>

            <Select
                name="cashflow_direction"
                defaultValue={defaultValue}
                onValueChange={(value) =>
                    setCashflowDirection(value as CategoryCashflowDirection)
                }
                required
            >
                <SelectTrigger id="cashflow_direction">
                    <SelectValue
                        placeholder={__('Choose how to report this transfer')}
                    />
                </SelectTrigger>
                <SelectContent>
                    {cashflowDirectionOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {selectedOption && (
                <p className="px-1 text-xs leading-relaxed text-muted-foreground">
                    {selectedOption.description}
                </p>
            )}

            <Alert>
                <Info className="h-4 w-4 opacity-50" />
                <AlertDescription className="text-xs leading-relaxed">
                    {__(
                        'Tracked transfers appear in the money flow Sankey chart. They are not counted as income or expenses.',
                    )}
                </AlertDescription>
            </Alert>
        </div>
    );
}
