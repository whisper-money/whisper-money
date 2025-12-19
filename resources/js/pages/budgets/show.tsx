import { index, show } from '@/actions/App/Http/Controllers/BudgetController';
import { AvailableToAssignCard } from '@/components/budgets/available-to-assign-card';
import { BudgetCategoryRow } from '@/components/budgets/budget-category-row';
import { DeleteBudgetDialog } from '@/components/budgets/delete-budget-dialog';
import { EditBudgetDialog } from '@/components/budgets/edit-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget, BudgetPeriod, getBudgetPeriodTypeLabel } from '@/types/budget';
import { router } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState, useMemo } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: BudgetPeriod;
}

export default function BudgetShow({ budget, currentPeriod }: Props) {
    const [allocations, setAllocations] = useState(
        currentPeriod.allocations || [],
    );
    const [isSaving, setIsSaving] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

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
        const totalAllocated = allocations.reduce(
            (sum, alloc) => sum + alloc.allocated_amount,
            0,
        );

        const totalIncome = 0;

        return {
            totalIncome,
            totalAllocated,
            carriedOver: currentPeriod.carried_over_amount,
        };
    }, [allocations, currentPeriod.carried_over_amount]);

    const handleAmountChange = (allocationId: string, newAmount: number) => {
        setAllocations((prev) =>
            prev.map((alloc) =>
                alloc.id === allocationId
                    ? { ...alloc, allocated_amount: newAmount }
                    : alloc,
            ),
        );
    };

    const handleSave = () => {
        setIsSaving(true);

        const allocationData = allocations.map((alloc) => ({
            budget_category_id: alloc.budget_category_id,
            allocated_amount: alloc.allocated_amount,
        }));

        router.patch(
            `/budget-periods/${currentPeriod.id}/allocations`,
            {
                allocations: allocationData,
            },
            {
                onFinish: () => setIsSaving(false),
            },
        );
    };

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
                            <Button
                                variant="outline"
                                onClick={handleSave}
                                disabled={isSaving}
                            >
                                {isSaving ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </ButtonGroup>
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

                <AvailableToAssignCard
                    totalIncome={stats.totalIncome}
                    totalAllocated={stats.totalAllocated}
                    carriedOver={stats.carriedOver}
                />

                <div className="space-y-4">
                    <h3 className="text-lg font-semibold">
                        Budget Categories
                    </h3>
                    {allocations.length > 0 ? (
                        <div className="space-y-3">
                            {allocations.map((allocation) => (
                                <BudgetCategoryRow
                                    key={allocation.id}
                                    allocation={allocation}
                                    onAmountChange={(amount) =>
                                        handleAmountChange(allocation.id, amount)
                                    }
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="flex h-[200px] items-center justify-center text-muted-foreground">
                            No categories in this budget.
                        </div>
                    )}
                </div>
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

