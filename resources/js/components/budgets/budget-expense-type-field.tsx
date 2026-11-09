import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    BUDGET_EXPENSE_TYPES,
    BudgetExpenseType,
    getBudgetExpenseTypeDescription,
    getBudgetExpenseTypeLabel,
} from '@/types/budget';
import { __ } from '@/utils/i18n';

interface Props {
    value: BudgetExpenseType;
    onChange: (value: BudgetExpenseType) => void;
}

/**
 * Picks whether a budget is fixed or variable spending, the split cashflow
 * shows in its Expenses card.
 */
export function BudgetExpenseTypeField({ value, onChange }: Props) {
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
