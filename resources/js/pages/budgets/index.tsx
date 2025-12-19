import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetListCard } from '@/components/budgets/budget-list-card';
import { CreateBudgetDialog } from '@/components/budgets/create-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget } from '@/types/budget';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Budgets',
        href: index().url,
    },
];

interface Props {
    budgets: Budget[];
}

export default function BudgetsIndex({ budgets }: Props) {
    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Budgets" />

            <div className="space-y-8 p-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Budgets"
                        description="Track your spending with flexible budgets"
                    />
                    <CreateBudgetDialog />
                </div>

                {budgets.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {budgets.map((budget) => (
                            <BudgetListCard key={budget.id} budget={budget} />
                        ))}
                    </div>
                ) : (
                    <div className="flex h-[300px] flex-col items-center justify-center gap-4 text-muted-foreground">
                        <p>No budgets found.</p>
                        <CreateBudgetDialog />
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    );
}

