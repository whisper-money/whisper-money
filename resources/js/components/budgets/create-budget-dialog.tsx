import { store } from '@/actions/App/Http/Controllers/BudgetController';
import { AmountInput } from '@/components/ui/amount-input';
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
import { Label as UILabel } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import {
    BUDGET_PERIOD_TYPES,
    BudgetPeriodType,
    getBudgetPeriodTypeLabel,
    getRolloverTypeLabel,
    ROLLOVER_TYPES,
    RolloverType,
} from '@/types/budget';
import { Category } from '@/types/category';
import { Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import React, { useState } from 'react';
import { Card, CardContent } from '../ui/card';

interface Props {
    className?: string;
    currencyCode?: string;
    trigger?: React.ReactNode;
}

export function CreateBudgetDialog({
    className = '',
    currencyCode = 'USD',
    trigger,
}: Props) {
    const page = usePage<SharedData>();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [periodType, setPeriodType] = useState<BudgetPeriodType>('monthly');
    const [periodStartDay, setPeriodStartDay] = useState<number>(1);
    const [selectedCategoryId, setSelectedCategoryId] = useState<string>('');
    const [selectedLabelId, setSelectedLabelId] = useState<string>('');
    const [allocatedAmount, setAllocatedAmount] = useState<number>(0);
    const [rolloverType, setRolloverType] =
        useState<RolloverType>('carry_over');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const allCategories = (page.props.categories as Category[]) || [];
    const allLabels = (page.props.labels as Label[]) || [];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        const newErrors: Record<string, string> = {};

        if (!selectedCategoryId && !selectedLabelId) {
            newErrors.selection = __(
                'You must select either a category or a label.',
            );
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setIsSubmitting(true);

        router.post(
            store().url,
            {
                name,
                period_type: periodType,
                period_start_day: periodStartDay,
                category_id: selectedCategoryId || null,
                label_id: selectedLabelId || null,
                rollover_type: rolloverType,
                allocated_amount: allocatedAmount,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setPeriodType('monthly');
                    setPeriodStartDay(1);
                    setSelectedCategoryId('');
                    setSelectedLabelId('');
                    setAllocatedAmount(0);
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
                {trigger ?? (
                    <Card
                        className={cn(
                            'cursor-pointer opacity-50 transition-opacity duration-200 hover:opacity-100',
                            className,
                        )}
                    >
                        <CardContent className="flex h-full items-center justify-center">
                            <div className="flex flex-row items-center justify-center gap-1">
                                <Plus className="mr-2 h-4 w-4" />
                                {__('Create Budget')}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[600px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>{__('Create Budget')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Set up a spending limit for a category or label.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-4">
                        <div className="space-y-2">
                            <UILabel htmlFor="name">
                                {__('Budget Name')}
                            </UILabel>
                            <Input
                                id="name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={__('e.g., Padel Budget')}
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <UILabel htmlFor="period-type">
                                {__('Period Type')}
                            </UILabel>
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

                        <div className="space-y-2">
                            <UILabel htmlFor="period-start-day">
                                {periodType === 'monthly'
                                    ? __('Start Day of Month')
                                    : __('Start Day')}
                            </UILabel>
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
                                    ? __(
                                          'Day of the month when the period starts (1-31)',
                                      )
                                    : periodType === 'weekly' ||
                                        periodType === 'biweekly'
                                      ? __('Day of week (0=Sunday, 6=Saturday)')
                                      : __('Starting day')}
                            </p>
                        </div>

                        <div className="space-y-4">
                            {errors.selection && (
                                <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {errors.selection}
                                </div>
                            )}

                            <div className="space-y-2">
                                <UILabel htmlFor="category">
                                    {__('Category (Optional)')}
                                </UILabel>
                                <div className="flex gap-2">
                                    <Select
                                        value={selectedCategoryId || undefined}
                                        onValueChange={setSelectedCategoryId}
                                    >
                                        <SelectTrigger
                                            id="category"
                                            className="flex-1"
                                        >
                                            <SelectValue
                                                placeholder={__(
                                                    'Select a category',
                                                )}
                                            />
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
                                <UILabel htmlFor="label">
                                    {__('Label (Optional)')}
                                </UILabel>
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
                                            <SelectValue
                                                placeholder={__(
                                                    'Select a label',
                                                )}
                                            />
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
                                            onClick={() =>
                                                setSelectedLabelId('')
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {__('Select at least a category or a label to track.')}
                                </p>
                            </div>

                            <div className="space-y-2">
                                <UILabel htmlFor="allocated-amount">
                                    {__('Allocated Amount')}
                                </UILabel>
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
                                        'How much do you want to budget per period?',
                                    )}
                                </p>
                            </div>

                            <div className="space-y-2">
                                <UILabel htmlFor="rollover">
                                    {__('Rollover Type')}
                                </UILabel>
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
                                        ? __(
                                              'Unused budget will carry over to the next period.',
                                          )
                                        : __(
                                              'Budget resets to zero at the start of each period.',
                                          )}
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
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                !name ||
                                (!selectedCategoryId && !selectedLabelId) ||
                                allocatedAmount <= 0
                            }
                        >
                            {isSubmitting
                                ? __('Creating...')
                                : __('Create Budget')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
