import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetListCard } from '@/components/budgets/budget-list-card';
import { CreateBudgetDialog } from '@/components/budgets/create-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { CreateSavingsGoalDialog } from '@/components/savings-goals/create-savings-goal-dialog';
import { SavingsGoalListCard } from '@/components/savings-goals/savings-goal-list-card';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Budget } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { ChevronDown, Plus } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Budgets',
        href: index().url,
    },
];

interface Props {
    budgets: Budget[];
    savingsGoals?: SavingsGoal[];
    savingsGoalsEnabled?: boolean;
    currencyCode: string;
}

export default function BudgetsIndex({
    budgets,
    savingsGoals = [],
    savingsGoalsEnabled = false,
    currencyCode,
}: Props) {
    const [createType, setCreateType] = useState<'budget' | 'goal' | null>(
        null,
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Budgets')} />

            <div className="space-y-8 p-6">
                <div className="flex items-center justify-between gap-2">
                    <HeadingSmall
                        title={__('Budgets')}
                        description={__(
                            'Track your spending and save toward your goals',
                        )}
                    />
                    {savingsGoalsEnabled ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button>
                                    <Plus />
                                    {__('Create')}
                                    <ChevronDown className="ml-1 h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {/* Let the menu close itself (no preventDefault, or
                                    it leaves pointer-events locked on the body), and
                                    defer opening the dialog to the next frame so it
                                    doesn't race the menu's close. */}
                                <DropdownMenuItem
                                    onSelect={() =>
                                        requestAnimationFrame(() =>
                                            setCreateType('budget'),
                                        )
                                    }
                                >
                                    {__('Budget')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() =>
                                        requestAnimationFrame(() =>
                                            setCreateType('goal'),
                                        )
                                    }
                                >
                                    {__('Savings Goal')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : (
                        <CreateBudgetDialog
                            currencyCode={currencyCode}
                            trigger={
                                <CreateButton>
                                    {__('Create Budget')}
                                </CreateButton>
                            }
                        />
                    )}
                </div>

                <section className="space-y-4">
                    {savingsGoalsEnabled && (
                        <h2 className="text-lg font-medium">{__('Budgets')}</h2>
                    )}
                    <div className="grid gap-4 lg:grid-cols-2">
                        {budgets.map((budget) => (
                            <BudgetListCard
                                key={budget.id}
                                budget={budget}
                                currencyCode={currencyCode}
                            />
                        ))}
                        <CreateBudgetDialog
                            currencyCode={currencyCode}
                            className={budgets.length ? '' : 'min-h-[260px]'}
                        />
                    </div>
                </section>

                {savingsGoalsEnabled && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-medium">
                            {__('Savings Goals')}
                        </h2>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {savingsGoals.map((goal) => (
                                <SavingsGoalListCard
                                    key={goal.id}
                                    savingsGoal={goal}
                                    currencyCode={currencyCode}
                                />
                            ))}
                            <CreateSavingsGoalDialog
                                currencyCode={currencyCode}
                                className={
                                    savingsGoals.length ? '' : 'min-h-[260px]'
                                }
                            />
                        </div>
                    </section>
                )}
            </div>

            {savingsGoalsEnabled && (
                <>
                    <CreateBudgetDialog
                        currencyCode={currencyCode}
                        open={createType === 'budget'}
                        onOpenChange={(open) => !open && setCreateType(null)}
                    />
                    <CreateSavingsGoalDialog
                        currencyCode={currencyCode}
                        open={createType === 'goal'}
                        onOpenChange={(open) => !open && setCreateType(null)}
                    />
                </>
            )}
        </AppSidebarLayout>
    );
}
