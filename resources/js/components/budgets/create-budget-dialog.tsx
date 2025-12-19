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
import { useDexie } from '@/hooks/use-dexie';
import {
    BUDGET_PERIOD_TYPES,
    BudgetPeriodType,
    getBudgetPeriodTypeLabel,
    getRolloverTypeLabel,
    ROLLOVER_TYPES,
    RolloverType,
} from '@/types/budget';
import { Category } from '@/types/category';
import { Label as LabelType } from '@/types/label';
import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

interface CategorySelection {
    category_id: string;
    rollover_type: RolloverType;
    label_ids: string[];
}

export function CreateBudgetDialog() {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [periodType, setPeriodType] = useState<BudgetPeriodType>('monthly');
    const [periodDuration, setPeriodDuration] = useState<number | null>(null);
    const [periodStartDay, setPeriodStartDay] = useState<number>(1);
    const [categories, setCategories] = useState<CategorySelection[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const { categories: allCategories, labels: allLabels } = useDexie();

    const handleAddCategory = () => {
        setCategories([
            ...categories,
            {
                category_id: '',
                rollover_type: 'carry_over',
                label_ids: [],
            },
        ]);
    };

    const handleRemoveCategory = (index: number) => {
        setCategories(categories.filter((_, i) => i !== index));
    };

    const handleCategoryChange = (
        index: number,
        field: keyof CategorySelection,
        value: any,
    ) => {
        const updated = [...categories];
        updated[index] = { ...updated[index], [field]: value };
        setCategories(updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        router.post(
            store().url,
            {
                name,
                period_type: periodType,
                period_duration: periodDuration,
                period_start_day: periodStartDay,
                categories,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setPeriodType('monthly');
                    setPeriodDuration(null);
                    setPeriodStartDay(1);
                    setCategories([]);
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
                            Set up a new budget to track your spending across
                            categories.
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

                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <Label>Categories</Label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={handleAddCategory}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Category
                                </Button>
                            </div>

                            {categories.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Add at least one category to track in this
                                    budget.
                                </p>
                            )}

                            {categories.map((cat, index) => (
                                <div
                                    key={index}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex items-start justify-between">
                                        <Label>Category {index + 1}</Label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                handleRemoveCategory(index)
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>

                                    <Select
                                        value={cat.category_id}
                                        onValueChange={(value) =>
                                            handleCategoryChange(
                                                index,
                                                'category_id',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select category" />
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

                                    <Select
                                        value={cat.rollover_type}
                                        onValueChange={(value) =>
                                            handleCategoryChange(
                                                index,
                                                'rollover_type',
                                                value as RolloverType,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {ROLLOVER_TYPES.map((type) => (
                                                <SelectItem
                                                    key={type}
                                                    value={type}
                                                >
                                                    {getRolloverTypeLabel(type)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ))}
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
                                categories.length === 0
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

