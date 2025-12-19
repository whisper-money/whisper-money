import { index, show, update } from '@/actions/App/Http/Controllers/BudgetController';
import { DeleteBudgetDialog } from '@/components/budgets/delete-budget-dialog';
import { EditBudgetDialog } from '@/components/budgets/edit-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget, BudgetPeriod, getBudgetPeriodTypeLabel } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import { router } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState, useMemo } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: BudgetPeriod;
}

export default function BudgetShow({ budget, currentPeriod }: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [isEditingAmount, setIsEditingAmount] = useState(false);
    const [editAmount, setEditAmount] = useState(
        (currentPeriod.allocated_amount / 100).toString(),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Budgets',
            href: index().url,
        },
        {
            title: budget.name,
            href: show({ budget: budget.id }).url,
        },
    ];

    const stats = useMemo(() => {
        const totalSpent =
            currentPeriod.budget_transactions?.reduce(
                (sum, t) => sum + t.amount,
                0,
            ) ?? 0;

        const remaining = currentPeriod.allocated_amount - totalSpent;
        const percentageUsed =
            currentPeriod.allocated_amount > 0
                ? (totalSpent / currentPeriod.allocated_amount) * 100
                : 0;

        return {
            totalSpent,
            remaining,
            percentageUsed,
            carriedOver: currentPeriod.carried_over_amount,
        };
    }, [currentPeriod]);

    const periodLabel = useMemo(() => {
        const start = new Date(currentPeriod.start_date).toLocaleDateString(
            'en-US',
            { month: 'long', day: 'numeric', year: 'numeric' },
        );
        const end = new Date(currentPeriod.end_date).toLocaleDateString(
            'en-US',
            { month: 'long', day: 'numeric', year: 'numeric' },
        );
        return `${start} - ${end}`;
    }, [currentPeriod]);

    const statusColor = useMemo(() => {
        if (stats.remaining < 0) return 'text-red-600 dark:text-red-400';
        if (stats.percentageUsed >= 80)
            return 'text-yellow-600 dark:text-yellow-400';
        return 'text-green-600 dark:text-green-400';
    }, [stats.remaining, stats.percentageUsed]);

    const handleSaveAmount = () => {
        const amount = Math.round(parseFloat(editAmount) * 100);
        if (!isNaN(amount) && amount >= 0) {
            router.patch(
                update({ budget: budget.id }).url,
                {
                    allocated_amount: amount,
                },
                {
                    onSuccess: () => setIsEditingAmount(false),
                },
            );
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            handleSaveAmount();
        } else if (e.key === 'Escape') {
            setEditAmount((currentPeriod.allocated_amount / 100).toString());
            setIsEditingAmount(false);
        }
    };

    const trackingLabel = useMemo(() => {
        if (budget.category) return `Category: ${budget.category.name}`;
        if (budget.label) return `Label: ${budget.label.name}`;
        return 'No tracking';
    }, [budget]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={budget.name} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-4 pl-1">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10">
                            <span className="text-lg font-medium text-primary">
                                {budget.name.charAt(0).toUpperCase()}
                            </span>
                        </div>
                        <HeadingSmall
                            title={budget.name}
                            description={`${getBudgetPeriodTypeLabel(budget.period_type as any)} · ${periodLabel}`}
                        />
                    </div>

                    <ButtonGroup>
                        <ButtonGroup>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="More options"
                                    >
                                        <ChevronDown className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit budget
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={() => setDeleteOpen(true)}
                                        variant="destructive"
                                    >
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </ButtonGroup>
                    </ButtonGroup>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between">
                            <div>
                                <CardTitle>Budget Overview</CardTitle>
                                <CardDescription>{trackingLabel}</CardDescription>
                            </div>
                            <Badge variant="outline">
                                {budget.rollover_type === 'carry_over'
                                    ? 'Carries Over'
                                    : 'Resets'}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    Allocated
                                </p>
                                {isEditingAmount ? (
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={editAmount}
                                        onChange={(e) =>
                                            setEditAmount(e.target.value)
                                        }
                                        onBlur={handleSaveAmount}
                                        onKeyDown={handleKeyDown}
                                        className="text-2xl font-bold"
                                        autoFocus
                                    />
                                ) : (
                                    <button
                                        onClick={() => setIsEditingAmount(true)}
                                        className="text-2xl font-bold hover:underline"
                                    >
                                        {formatCurrency(
                                            currentPeriod.allocated_amount,
                                        )}
                                    </button>
                                )}
                            </div>

                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    Spent
                                </p>
                                <p className="text-2xl font-bold">
                                    {formatCurrency(stats.totalSpent)}
                                </p>
                            </div>

                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    Remaining
                                </p>
                                <p className={`text-2xl font-bold ${statusColor}`}>
                                    {formatCurrency(stats.remaining)}
                                </p>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Progress
                                value={Math.min(stats.percentageUsed, 100)}
                                className="h-3"
                            />
                            <p className="text-center text-sm text-muted-foreground">
                                {stats.percentageUsed.toFixed(1)}% used
                            </p>
                        </div>

                        {stats.carriedOver > 0 && (
                            <div className="rounded-lg bg-muted p-4">
                                <p className="text-sm text-muted-foreground">
                                    Carried over from previous period:
                                </p>
                                <p className="text-lg font-semibold">
                                    {formatCurrency(stats.carriedOver)}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <EditBudgetDialog
                budget={budget}
                open={editOpen}
                onOpenChange={setEditOpen}
            />

            <DeleteBudgetDialog
                budget={budget}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                redirectTo={index().url}
            />
        </AppSidebarLayout>
    );
}
