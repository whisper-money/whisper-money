import { update } from '@/actions/App/Http/Controllers/BudgetController';
import { CategoryBadge } from '@/components/shared/category-combobox';
import { LabelBadge } from '@/components/shared/label-combobox';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AmountInput } from '@/components/ui/amount-input';
import { Badge } from '@/components/ui/badge';
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
    Budget,
    BUDGET_PERIOD_TYPES,
    BudgetPeriodType,
    getBudgetPeriodTypeLabel,
    getRolloverTypeLabel,
    ROLLOVER_TYPES,
    RolloverType,
} from '@/types/budget';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: { allocated_amount: number };
    currencyCode?: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditBudgetDialog({
    budget,
    currentPeriod,
    currencyCode = 'USD',
    open,
    onOpenChange,
}: Props) {
    const [name, setName] = useState(budget.name);
    const [periodType, setPeriodType] = useState<BudgetPeriodType>(
        budget.period_type as BudgetPeriodType,
    );
    const [periodStartDay, setPeriodStartDay] = useState<number>(
        budget.period_start_day || 1,
    );
    const [allocatedAmount, setAllocatedAmount] = useState<number>(
        currentPeriod.allocated_amount,
    );
    const [rolloverType, setRolloverType] = useState<RolloverType>(
        budget.rollover_type as RolloverType,
    );
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (open && budget) {
            setName(budget.name);
            setPeriodType(budget.period_type as BudgetPeriodType);
            setPeriodStartDay(budget.period_start_day || 1);
            setAllocatedAmount(currentPeriod.allocated_amount);
            setRolloverType(budget.rollover_type as RolloverType);
        }
    }, [open, budget, currentPeriod]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        router.patch(
            update({ budget: budget.id }).url,
            {
                name,
                period_type: periodType,
                period_start_day: periodType === 'yearly' ? 1 : periodStartDay,
                allocated_amount: allocatedAmount,
                rollover_type: rolloverType,
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
                        <DialogTitle>{__('Edit Budget')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Update your budget settings. To change the allocated\n                            amount or tracking, use the budget page directly.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <Alert>
                            <Info className="h-4 w-4 opacity-50" />
                            <AlertDescription>
                                {__(
                                    'Period and carry-over settings cannot be changed after a budget is created because budgets are calculated historically. If you need different settings, delete this budget and create a new one.',
                                )}
                            </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                            <Label htmlFor="name">{__('Budget Name')}</Label>
                            <Input
                                id="name"
                                value={name}
                                className="mt-1"
                                onChange={(e) => setName(e.target.value)}
                                placeholder={__('e.g., Monthly Budget')}
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>{__('Tracking')}</Label>
                            {budget.is_catch_all ? (
                                <>
                                    <div className="flex flex-wrap items-center gap-1">
                                        <Badge variant="secondary">
                                            {__('All untracked expenses')}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'This catch-all budget tracks every expense that no other budget covers.',
                                        )}
                                    </p>
                                </>
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-1">
                                        {budget.categories?.map((category) => (
                                            <CategoryBadge
                                                key={category.id}
                                                category={category}
                                            />
                                        ))}
                                        {budget.labels?.map((label) => (
                                            <LabelBadge
                                                key={label.id}
                                                label={label}
                                            />
                                        ))}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'Tracked categories and labels cannot be changed after creation.',
                                        )}
                                    </p>
                                </>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="period-type">
                                {__('Period Type')}
                            </Label>
                            <div className="mt-1">
                                <Select
                                    value={periodType}
                                    disabled
                                    onValueChange={(value) => {
                                        setPeriodType(
                                            value as BudgetPeriodType,
                                        );
                                        setPeriodStartDay(1);
                                    }}
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
                        </div>

                        {periodType !== 'yearly' && (
                            <div className="space-y-2">
                                <Label htmlFor="period-start-day">
                                    {periodType === 'monthly'
                                        ? __('Start Day of Month')
                                        : __('Start Day')}
                                </Label>
                                <Input
                                    id="period-start-day"
                                    disabled
                                    type="number"
                                    className="mt-1"
                                    min="0"
                                    max={periodType === 'monthly' ? '31' : '6'}
                                    value={periodStartDay}
                                    onChange={(e) =>
                                        setPeriodStartDay(
                                            parseInt(e.target.value),
                                        )
                                    }
                                />

                                <p className="text-sm text-muted-foreground">
                                    {periodType === 'monthly'
                                        ? __(
                                              'Day of the month when the period starts (1-31)',
                                          )
                                        : __(
                                              'Day of week (0=Sunday, 6=Saturday)',
                                          )}
                                </p>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="allocated-amount">
                                {__('Allocated Amount')}
                            </Label>
                            <AmountInput
                                id="allocated-amount"
                                value={allocatedAmount}
                                onChange={setAllocatedAmount}
                                currencyCode={currencyCode}
                                placeholder="0.00"
                                required
                            />

                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'This will update the allocated amount for the\n                                current and future periods.',
                                )}
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="rollover">
                                {__('Rollover Type')}
                            </Label>
                            <div className="mt-1">
                                <Select
                                    disabled
                                    value={rolloverType}
                                    onValueChange={(value) =>
                                        setRolloverType(value as RolloverType)
                                    }
                                >
                                    <SelectTrigger id="rollover">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ROLLOVER_TYPES.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {getRolloverTypeLabel(type)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {rolloverType === 'carry_over'
                                    ? __(
                                          'Unused budget will carry over to the next period.',
                                      )
                                    : __(
                                          'Budget resets to zero at the start of each period.',
                                      )}
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {__('Cancel')}
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
