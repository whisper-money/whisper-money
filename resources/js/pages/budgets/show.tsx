import { index, show } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetSpendingChart } from '@/components/budgets/budget-spending-chart';
import { DeleteBudgetDialog } from '@/components/budgets/delete-budget-dialog';
import { EditBudgetDialog } from '@/components/budgets/edit-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { TransactionList } from '@/components/transactions/transaction-list';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { Account } from '@/types/account';
import { Bank } from '@/types/account';
import { BreadcrumbItem } from '@/types';
import { Budget, BudgetPeriod, getBudgetPeriodTypeLabel } from '@/types/budget';
import { Category } from '@/types/category';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState, useMemo } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: BudgetPeriod;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    currencyCode: string;
}

export default function BudgetShow({
    budget,
    currentPeriod,
    categories,
    accounts,
    banks,
    currencyCode,
}: Props) {
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

    const trackingLabel = useMemo(() => {
        if (budget.category) return budget.category.name;
        if (budget.label) return budget.label.name;
        return 'No tracking';
    }, [budget]);

    const periodTransactions = useMemo(() => {
        return (
            currentPeriod.budget_transactions
                ?.map((bt) => bt.transaction)
                .filter((t) => t !== undefined && t !== null) || []
        );
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
                            description={`${trackingLabel} · ${getBudgetPeriodTypeLabel(budget.period_type)} · ${periodLabel}`}
                        />
                    </div>

                    <ButtonGroup>
                        <Button
                            variant="outline"
                            onClick={() => setEditOpen(true)}
                        >
                            Edit budget
                        </Button>
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
                                    onClick={() => setDeleteOpen(true)}
                                    variant="destructive"
                                >
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </ButtonGroup>
                </div>

                <BudgetSpendingChart
                    currentPeriod={currentPeriod}
                    budgetName={budget.name}
                    currencyCode={currencyCode}
                />

                <TransactionList
                    categories={categories}
                    accounts={accounts}
                    banks={banks}
                    transactions={periodTransactions}
                    pageSize={10}
                    showActionsMenu={false}
                    maxHeight={600}
                />
            </div>

                <EditBudgetDialog
                    budget={budget}
                    currentPeriod={currentPeriod}
                    currencyCode={currencyCode}
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
