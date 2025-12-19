import { BudgetHistoryChart } from '@/components/budgets/budget-history-chart';
import HeadingSmall from '@/components/heading-small';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget, BudgetCategory, BudgetHistoryData } from '@/types/budget';
import { Head } from '@inertiajs/react';

interface Props {
    budget: Budget;
    budgetCategory: BudgetCategory;
    historyData: BudgetHistoryData[];
}

export default function BudgetAnalytics({
    budget,
    budgetCategory,
    historyData,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Budgets',
            href: '/budgets',
        },
        {
            title: budget.name,
            href: `/budgets/${budget.id}`,
        },
        {
            title: 'Analytics',
            href: `/budgets/${budget.id}/analytics/${budgetCategory.id}`,
        },
    ];

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={`${budget.name} - Analytics`} />

            <div className="space-y-8 p-6">
                <HeadingSmall
                    title={`${budgetCategory.category?.name} Analytics`}
                    description={`Historical spending data for ${budget.name}`}
                />

                <BudgetHistoryChart
                    data={historyData}
                    categoryName={budgetCategory.category?.name || 'Category'}
                />
            </div>
        </AppSidebarLayout>
    );
}

