import { update } from '@/actions/App/Http/Controllers/BudgetController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    BUDGET_PERIOD_TYPES,
    Budget,
    BudgetPeriodType,
    getBudgetPeriodTypeLabel,
} from '@/types/budget';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Props {
    budget: Budget;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditBudgetDialog({ budget, open, onOpenChange }: Props) {
    const [name, setName] = useState(budget.name);
    const [periodType, setPeriodType] = useState<BudgetPeriodType>(
        budget.period_type as BudgetPeriodType,
    );
    const [periodDuration, setPeriodDuration] = useState<number | null>(
        budget.period_duration,
    );
    const [periodStartDay, setPeriodStartDay] = useState<number>(
        budget.period_start_day,
    );
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (open && budget) {
            setName(budget.name);
            setPeriodType(budget.period_type as BudgetPeriodType);
            setPeriodDuration(budget.period_duration);
            setPeriodStartDay(budget.period_start_day);
        }
    }, [open, budget]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        router.patch(
            update({ budget: budget.id }).url,
            {
                name,
                period_type: periodType,
                period_duration: periodDuration,
                period_start_day: periodStartDay,
            },
            {
                onSuccess: () => {
                    onOpenChange(false);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[600px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>Edit Budget</DialogTitle>
                        <DialogDescription>
                            Update your budget name and period settings. To
                            change the tracked category or allocated amount, use
                            the budget page directly.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">Budget Name</Label>
                            <Input
                                id="name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g., Monthly Budget"
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="period-type">Period Type</Label>
                            <Select
                                value={periodType}
                                onValueChange={(value) =>
                                    setPeriodType(value as BudgetPeriodType)
                                }
                            >
                                <SelectTrigger id="period-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {BUDGET_PERIOD_TYPES.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {getBudgetPeriodTypeLabel(type)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {periodType === 'custom' && (
                            <div className="space-y-2">
                                <Label htmlFor="period-duration">
                                    Period Duration (days)
                                </Label>
                                <Input
                                    id="period-duration"
                                    type="number"
                                    min="1"
                                    max="365"
                                    value={periodDuration ?? ''}
                                    onChange={(e) =>
                                        setPeriodDuration(
                                            e.target.value
                                                ? parseInt(e.target.value)
                                                : null,
                                        )
                                    }
                                    required={periodType === 'custom'}
                                />
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="period-start-day">
                                {periodType === 'monthly'
                                    ? 'Start Day of Month'
                                    : 'Start Day'}
                            </Label>
                            <Input
                                id="period-start-day"
                                type="number"
                                min="0"
                                max={periodType === 'monthly' ? '31' : '6'}
                                value={periodStartDay}
                                onChange={(e) =>
                                    setPeriodStartDay(parseInt(e.target.value))
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {periodType === 'monthly'
                                    ? 'Day of the month when the period starts (1-31)'
                                    : periodType === 'weekly' ||
                                        periodType === 'biweekly'
                                      ? 'Day of week (0=Sunday, 6=Saturday)'
                                      : 'Starting day'}
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting || !name}>
                            {isSubmitting ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

