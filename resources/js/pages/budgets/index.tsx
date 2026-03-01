import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetListCard } from '@/components/budgets/budget-list-card';
import { CreateBudgetDialog } from '@/components/budgets/create-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { CreateButton } from '@/components/ui/create-button';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget } from '@/types/budget';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Budgets',
        href: index().url,
    },
];

interface Props {
    budgets: Budget[];
    currencyCode: string;
}

export default function BudgetsIndex({ budgets, currencyCode }: Props) {
    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Budgets')} />

            <div className="space-y-8 p-6">
                <div className="flex items-center justify-between gap-2">
                    <HeadingSmall
                        title={__('Budgets')}
                        description={__(
                            'Track your spending with flexible budgets',
                        )}
                    />
                    <CreateBudgetDialog
                        currencyCode={currencyCode}
                        trigger={
                            <CreateButton>{__('Create Budget')}</CreateButton>
                        }
                    />
                </div>

                {budgets.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {budgets.map((budget) => (
                            <BudgetListCard
                                key={budget.id}
                                budget={budget}
                                currencyCode={currencyCode}
                            />
                        ))}
                        <CreateBudgetDialog currencyCode={currencyCode} />
                    </div>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <CreateBudgetDialog
                            className="min-h-[260px]"
                            currencyCode={currencyCode}
                        />
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    );
}
