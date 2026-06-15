import { index, show } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetPeriodNavigation } from '@/components/budgets/budget-period-navigation';
import { BudgetSpendingChart } from '@/components/budgets/budget-spending-chart';
import { DeleteBudgetDialog } from '@/components/budgets/delete-budget-dialog';
import { EditBudgetDialog } from '@/components/budgets/edit-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { MobileBackButton } from '@/components/mobile-back-button';
import { CategoryBadge } from '@/components/shared/category-combobox';
import { LabelBadge } from '@/components/shared/label-combobox';
import { TransactionList } from '@/components/transactions/transaction-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Budget, BudgetPeriod } from '@/types/budget';
import { Category } from '@/types/category';
import { Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    budget: Budget;
    currentPeriod: BudgetPeriod;
    previousPeriod: BudgetPeriod | null;
    nextPeriod: BudgetPeriod | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    currencyCode: string;
}

export default function BudgetShow({
    budget,
    currentPeriod,
    previousPeriod,
    nextPeriod,
    categories,
    accounts,
    banks,
    labels,
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

    const trackingCount =
        (budget.categories?.length ?? 0) + (budget.labels?.length ?? 0);

    const periodTransactions = useMemo(() => {
        return (
            currentPeriod.budget_transactions
                ?.map((bt) => bt.transaction)
                .filter((t) => t !== undefined && t !== null) || []
        );
    }, [currentPeriod]);

    return (
        <AppSidebarLayout
            breadcrumbs={breadcrumbs}
            mobileLeading={<MobileBackButton href={index().url} />}
        >
            <Head title={budget.name} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-4">
                        <HeadingSmall
                            title={budget.name}
                            description={
                                <div className="flex flex-row flex-wrap items-center gap-1 text-sm">
                                    {budget.is_catch_all ? (
                                        <Badge variant="secondary">
                                            {__('All untracked expenses')}
                                        </Badge>
                                    ) : trackingCount > 0 ? (
                                        <>
                                            <span className="opacity-50">
                                                {__('Tracking')}{' '}
                                            </span>
                                            {budget.categories?.map(
                                                (category) => (
                                                    <CategoryBadge
                                                        key={category.id}
                                                        category={category}
                                                    />
                                                ),
                                            )}
                                            {budget.labels?.map((label) => (
                                                <LabelBadge
                                                    key={label.id}
                                                    label={label}
                                                />
                                            ))}
                                        </>
                                    ) : (
                                        <span className="opacity-50">
                                            {__('No tracking')}
                                        </span>
                                    )}
                                </div>
                            }
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <BudgetPeriodNavigation
                            budget={budget}
                            currentPeriod={currentPeriod}
                            previousPeriod={previousPeriod}
                            nextPeriod={nextPeriod}
                        />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label={__('More options')}
                                >
                                    <ChevronDown className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onClick={() => setEditOpen(true)}
                                >
                                    {__('Edit budget')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() => setDeleteOpen(true)}
                                    variant="destructive"
                                >
                                    {__('Delete budget')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
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
                                    {__('Finding historical transactions')}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        "We're looking through your transaction\n                                    history to find expenses that match this\n                                    budget. This usually takes a few seconds.",
                                    )}
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
                        labels={labels}
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
