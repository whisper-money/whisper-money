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
import { Skeleton } from '@/components/ui/skeleton';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Account, Bank } from '@/types/account';
import { Budget, BudgetPeriod, getBudgetPeriodTypeLabel } from '@/types/budget';
import { Category } from '@/types/category';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: BudgetPeriod;
    previousPeriod: BudgetPeriod | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    currencyCode: string;
}

export default function BudgetShow({
    budget,
    currentPeriod,
    previousPeriod,
    categories,
    accounts,
    banks,
    currencyCode,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    // Poll for updates when processing historical transactions
    useEffect(() => {
        if (!currentPeriod.processing_historical) {
            return;
        }

        const interval = setInterval(() => {
            router.reload({
                only: ['currentPeriod'],
                preserveScroll: true,
            });
        }, 3000); // Poll every 3 seconds

        return () => clearInterval(interval);
    }, [currentPeriod.processing_historical]);

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
            { month: 'short', day: 'numeric', year: '2-digit' },
        );
        const end = new Date(currentPeriod.end_date).toLocaleDateString(
            'en-US',
            { month: 'short', day: 'numeric', year: '2-digit' },
        );
        return `${start} - ${end}`;
    }, [currentPeriod]);

    const trackingLabel = useMemo((): string | null => {
        if (budget.category) return budget.category.name;
        if (budget.label) return budget.label.name;
        return null;
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
                    <div className="flex items-center gap-4">
                        <HeadingSmall
                            title={budget.name}
                            description={
                                <div className="flex flex-row items-center gap-1 text-sm">
                                    <div className="inline">
                                        {trackingLabel !== null ? (
                                            <>
                                                <span className="opacity-50">
                                                    Tracking{' '}
                                                </span>
                                                <span>{trackingLabel}</span>
                                            </>
                                        ) : (
                                            <span className="opacity-50">
                                                No tracking
                                            </span>
                                        )}
                                    </div>
                                    <span className="opacity-25">/</span>
                                    <div className="inline">
                                        <span>{periodLabel} </span>
                                        <span className="opacity-50">
                                            (
                                            {getBudgetPeriodTypeLabel(
                                                budget.period_type,
                                            )}
                                            )
                                        </span>
                                    </div>
                                </div>
                            }
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
                    previousPeriod={previousPeriod}
                    budgetName={budget.name}
                    currencyCode={currencyCode}
                />

                {currentPeriod.processing_historical ? (
                    <div className="space-y-4 rounded-lg border border-border bg-card p-6">
                        <div className="flex items-center gap-3">
                            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                            <div>
                                <h3 className="text-sm font-medium">
                                    Finding historical transactions
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    We're looking through your transaction
                                    history to find expenses that match this
                                    budget. This usually takes a few seconds.
                                </p>
                            </div>
                        </div>

                        <div className="space-y-3">
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-16 w-full" />
                        </div>
                    </div>
                ) : (
                    <TransactionList
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        transactions={periodTransactions}
                        pageSize={10}
                        showActionsMenu={false}
                        maxHeight={600}
                    />
                )}
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
