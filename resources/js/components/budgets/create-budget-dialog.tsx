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
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { buildCategoryTree, flattenCategoryTree } from '@/lib/category-tree';
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
import { Category, getCategoryColorClasses } from '@/types/category';
import { getLabelColorClasses, Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { Plus, Tag } from 'lucide-react';
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
    const [selectedCategoryIds, setSelectedCategoryIds] = useState<string[]>(
        [],
    );
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
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

        if (selectedCategoryIds.length === 0 && selectedLabelIds.length === 0) {
            newErrors.selection = __(
                'You must select at least one category or label.',
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
                period_start_day: periodType === 'yearly' ? 1 : periodStartDay,
                category_ids: selectedCategoryIds,
                label_ids: selectedLabelIds,
                rollover_type: rolloverType,
                allocated_amount: allocatedAmount,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setPeriodType('monthly');
                    setPeriodStartDay(1);
                    setSelectedCategoryIds([]);
                    setSelectedLabelIds([]);
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
                                'Set up a spending limit across one or more categories or labels.',
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
                                onValueChange={(value) => {
                                    setPeriodType(value as BudgetPeriodType);
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

                        {periodType !== 'yearly' && (
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

                        <div className="space-y-4">
                            {errors.selection && (
                                <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {errors.selection}
                                </div>
                            )}

                            <div className="space-y-2">
                                <UILabel htmlFor="categories">
                                    {__('Categories')}
                                </UILabel>
                                <MultiSelect
                                    id="categories"
                                    options={flattenCategoryTree(
                                        buildCategoryTree(allCategories),
                                    ).map((category) => {
                                        const colorClasses =
                                            getCategoryColorClasses(
                                                category.color,
                                            );
                                        const IconComponent = Icons[
                                            category.icon as keyof typeof Icons
                                        ] as Icons.LucideIcon | undefined;

                                        return {
                                            value: category.id,
                                            label: category.name,
                                            depth: category.depth,
                                            parentValue: category.parent_id,
                                            icon: IconComponent ? (
                                                <IconComponent className="h-3 w-3 opacity-80" />
                                            ) : undefined,
                                            badgeClassName: cn(
                                                colorClasses.bg,
                                                colorClasses.text,
                                            ),
                                        };
                                    })}
                                    selected={selectedCategoryIds}
                                    onChange={setSelectedCategoryIds}
                                    placeholder={__('Select categories')}
                                    searchPlaceholder={__('Search categories…')}
                                    emptyText={__('No categories found.')}
                                />
                            </div>

                            <div className="space-y-2">
                                <UILabel htmlFor="labels">
                                    {__('Labels')}
                                </UILabel>
                                <MultiSelect
                                    id="labels"
                                    options={allLabels.map((label) => {
                                        const colorClasses =
                                            getLabelColorClasses(label.color);

                                        return {
                                            value: label.id,
                                            label: label.name,
                                            icon: (
                                                <Tag className="h-3 w-3 opacity-80" />
                                            ),
                                            badgeClassName: cn(
                                                colorClasses.bg,
                                                colorClasses.text,
                                            ),
                                        };
                                    })}
                                    selected={selectedLabelIds}
                                    onChange={setSelectedLabelIds}
                                    placeholder={__('Select labels')}
                                    searchPlaceholder={__('Search labels…')}
                                    emptyText={__('No labels found.')}
                                />
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Select at least one category or label to track.',
                                    )}
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
                                (selectedCategoryIds.length === 0 &&
                                    selectedLabelIds.length === 0) ||
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
