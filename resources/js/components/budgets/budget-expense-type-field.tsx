import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SharedData } from '@/types';
import {
    BUDGET_EXPENSE_TYPES,
    BudgetExpenseType,
    getBudgetExpenseTypeDescription,
    getBudgetExpenseTypeLabel,
} from '@/types/budget';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';

interface Props {
    value: BudgetExpenseType;
    onChange: (value: BudgetExpenseType) => void;
}

/**
 * Picks whether a budget is fixed or variable spending. Only cashflow reads
 * it, so the field stays hidden from users who cannot see that split yet.
 */
export function BudgetExpenseTypeField({ value, onChange }: Props) {
    const { features } = usePage<SharedData>().props;

    if (!features.cashflowAvailableFree) {
        return null;
    }

    return (
        <div className="space-y-2">
            <Label htmlFor="expense-type">{__('Expense Type')}</Label>
            <Select
                value={value}
                onValueChange={(type) => onChange(type as BudgetExpenseType)}
            >
                <SelectTrigger id="expense-type">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {BUDGET_EXPENSE_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                            {getBudgetExpenseTypeLabel(type)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
                {getBudgetExpenseTypeDescription(value)}
            </p>
        </div>
    );
}
