import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { BudgetPeriodAllocation } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import { useMemo, useState } from 'react';

interface Props {
    allocation: BudgetPeriodAllocation;
    onAmountChange: (amount: number) => void;
}

export function BudgetCategoryRow({ allocation, onAmountChange }: Props) {
    const [isEditing, setIsEditing] = useState(false);
    const [editValue, setEditValue] = useState(
        (allocation.allocated_amount / 100).toString(),
    );

    const spent = useMemo(() => {
        return (
            allocation.budget_transactions?.reduce(
                (sum, t) => sum + t.amount,
                0,
            ) ?? 0
        );
    }, [allocation.budget_transactions]);

    const remaining = allocation.allocated_amount - spent;
    const percentageUsed =
        allocation.allocated_amount > 0
            ? (spent / allocation.allocated_amount) * 100
            : 0;

    const statusColor = useMemo(() => {
        if (remaining < 0) return 'text-red-600 dark:text-red-400';
        if (percentageUsed >= 80)
            return 'text-yellow-600 dark:text-yellow-400';
        return 'text-green-600 dark:text-green-400';
    }, [remaining, percentageUsed]);

    const handleSave = () => {
        const amount = Math.round(parseFloat(editValue) * 100);
        if (!isNaN(amount) && amount >= 0) {
            onAmountChange(amount);
        }
        setIsEditing(false);
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            handleSave();
        } else if (e.key === 'Escape') {
            setEditValue((allocation.allocated_amount / 100).toString());
            setIsEditing(false);
        }
    };

    return (
        <div className="space-y-3 rounded-lg border p-4 transition-colors hover:bg-muted/50">
            <div className="flex items-start justify-between">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <h4 className="font-medium">
                            {allocation.budget_category?.category?.name}
                        </h4>
                        {allocation.budget_category?.labels &&
                            allocation.budget_category.labels.length > 0 && (
                                <div className="flex gap-1">
                                    {allocation.budget_category.labels.map(
                                        (label) => (
                                            <Badge
                                                key={label.id}
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                {label.name}
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {allocation.budget_category?.rollover_type ===
                        'carry_over'
                            ? 'Carries over'
                            : 'Resets each period'}
                    </p>
                </div>

                <div className="text-right">
                    {isEditing ? (
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={editValue}
                            onChange={(e) => setEditValue(e.target.value)}
                            onBlur={handleSave}
                            onKeyDown={handleKeyDown}
                            className="w-32 text-right"
                            autoFocus
                        />
                    ) : (
                        <button
                            onClick={() => setIsEditing(true)}
                            className="text-lg font-semibold hover:underline"
                        >
                            {formatCurrency(allocation.allocated_amount)}
                        </button>
                    )}
                    <p className="text-xs text-muted-foreground">Allocated</p>
                </div>
            </div>

            <div className="space-y-2">
                <Progress
                    value={Math.min(percentageUsed, 100)}
                    className="h-2"
                />
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        Spent: {formatCurrency(spent)}
                    </span>
                    <span className={statusColor}>
                        Remaining: {formatCurrency(remaining)}
                    </span>
                </div>
            </div>
        </div>
    );
}

