import { store } from '@/actions/App/Http/Controllers/BudgetController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { db } from '@/lib/dexie-db';
import {
    BUDGET_PERIOD_TYPES,
    BudgetPeriodType,
    getBudgetPeriodTypeLabel,
    getRolloverTypeLabel,
    ROLLOVER_TYPES,
    RolloverType,
} from '@/types/budget';
import { router, usePage } from '@inertiajs/react';
import { useLiveQuery } from 'dexie-react-hooks';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export function CreateBudgetDialog() {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [periodType, setPeriodType] = useState<BudgetPeriodType>('monthly');
    const [periodDuration, setPeriodDuration] = useState<number | null>(null);
    const [periodStartDay, setPeriodStartDay] = useState<number>(1);
    const [selectedCategoryId, setSelectedCategoryId] = useState<string>('');
    const [selectedLabelId, setSelectedLabelId] = useState<string>('');
    const [allocatedAmount, setAllocatedAmount] = useState<string>('');
    const [rolloverType, setRolloverType] = useState<RolloverType>('carry_over');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const allCategories = useLiveQuery(() => db.categories.toArray(), []) || [];
    const allLabels = useLiveQuery(() => db.labels.toArray(), []) || [];

    const page = usePage();
    const serverErrors = (page.props.errors as Record<string, string>) || {};

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        const newErrors: Record<string, string> = {};

        if (!selectedCategoryId && !selectedLabelId) {
            newErrors.selection =
                'You must select either a category or a label.';
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setIsSubmitting(true);

        const amountInCents = Math.round(parseFloat(allocatedAmount) * 100);

        router.post(
            store().url,
            {
                name,
                period_type: periodType,
                period_duration: periodDuration,
                period_start_day: periodStartDay,
                category_id: selectedCategoryId || null,
                label_id: selectedLabelId || null,
                rollover_type: rolloverType,
                allocated_amount: amountInCents,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setPeriodType('monthly');
                    setPeriodDuration(null);
                    setPeriodStartDay(1);
                    setSelectedCategoryId('');
                    setSelectedLabelId('');
                    setAllocatedAmount('');
                    setRolloverType('carry_over');
                    setErrors({});
                },
                onError: (errors) => {
                    setErrors(errors as Record<string, string>);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Create Budget
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[600px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>Create Budget</DialogTitle>
                        <DialogDescription>
                            Set up a spending limit for a category or label.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">Budget Name</Label>
                            <Input
                                id="name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g., Padel Budget"
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

                        <div className="space-y-4">
                            {(errors.selection || serverErrors.selection) && (
                                <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {errors.selection || serverErrors.selection}
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="category">
                                    Category (Optional)
                                </Label>
                                <div className="flex gap-2">
                                    <Select
                                        value={selectedCategoryId || undefined}
                                        onValueChange={setSelectedCategoryId}
                                    >
                                        <SelectTrigger
                                            id="category"
                                            className="flex-1"
                                        >
                                            <SelectValue placeholder="Select a category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {allCategories.map((category) => (
                                                <SelectItem
                                                    key={category.id}
                                                    value={category.id}
                                                >
                                                    {category.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {selectedCategoryId && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            onClick={() =>
                                                setSelectedCategoryId('')
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="label">Label (Optional)</Label>
                                <div className="flex gap-2">
                                    <Select
                                        value={selectedLabelId || undefined}
                                        onValueChange={(value) =>
                                            setSelectedLabelId(value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="label"
                                            className="flex-1"
                                        >
                                            <SelectValue placeholder="Select a label" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {allLabels.map((label) => (
                                                <SelectItem
                                                    key={label.id}
                                                    value={label.id}
                                                >
                                                    {label.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {selectedLabelId && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            onClick={() => setSelectedLabelId('')}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Select at least a category or a label to
                                    track.
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="allocated-amount">
                                    Allocated Amount
                                </Label>
                                <Input
                                    id="allocated-amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={allocatedAmount}
                                    onChange={(e) =>
                                        setAllocatedAmount(e.target.value)
                                    }
                                    placeholder="0.00"
                                    required
                                />
                                <p className="text-sm text-muted-foreground">
                                    How much do you want to budget per period?
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="rollover">Rollover Type</Label>
                                <Select
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
                                <p className="text-sm text-muted-foreground">
                                    {rolloverType === 'carry_over'
                                        ? 'Unused budget will carry over to the next period.'
                                        : 'Budget resets to zero at the start of each period.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                !name ||
                                (!selectedCategoryId && !selectedLabelId) ||
                                !allocatedAmount ||
                                parseFloat(allocatedAmount) <= 0
                            }
                        >
                            {isSubmitting ? 'Creating...' : 'Create Budget'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
