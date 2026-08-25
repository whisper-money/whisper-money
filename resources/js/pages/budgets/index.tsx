import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetListCard } from '@/components/budgets/budget-list-card';
import { CreateBudgetDialog } from '@/components/budgets/create-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { CreateSavingsGoalDialog } from '@/components/savings-goals/create-savings-goal-dialog';
import { SavingsGoalListCard } from '@/components/savings-goals/savings-goal-list-card';
import { CreatePlaceholderCard } from '@/components/shared/create-placeholder-card';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useLocale } from '@/hooks/use-locale';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { mergePlanningItems } from '@/lib/planning-items';
import { BreadcrumbItem } from '@/types';
import { Budget } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, Plus } from 'lucide-react';
import { ReactNode, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Planning',
        href: index().url,
    },
];

export type BudgetTypeFilter = 'all' | 'budgets' | 'goals';

export function budgetTypeFilterFromUrl(url: string): BudgetTypeFilter {
    const show = new URL(url, 'https://localhost').searchParams.get('show');

    return show === 'budgets' || show === 'goals' ? show : 'all';
}

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
    const { url } = usePage();
    const locale = useLocale();
    // Without savings goals there is no toggle to undo a ?show= filter coming
    // from a stale bookmark, so clamp it to 'all' instead of serving a list
    // the user has no way to unfilter.
    const [filter, setFilter] = useState<BudgetTypeFilter>(() =>
        savingsGoalsEnabled ? budgetTypeFilterFromUrl(url) : 'all',
    );

    const changeFilter = (value: string) => {
        const next = (value || 'all') as BudgetTypeFilter;
        setFilter(next);
        // preserveState defaults to false on client-side visits, which would
        // remount the page and reset local state (see use-period-url-sync.ts).
        router.replace({
            url: next === 'all' ? index().url : `${index().url}?show=${next}`,
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Budgets and savings goals share one list: whichever needs attention
    // first leads it, and neither type is stuck below the other.
    const items = useMemo(
        () =>
            mergePlanningItems(
                filter === 'goals' ? [] : budgets,
                savingsGoalsEnabled && filter !== 'budgets' ? savingsGoals : [],
                locale,
            ),
        [budgets, savingsGoals, savingsGoalsEnabled, filter, locale],
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Planning')} />

            <div className="space-y-8 p-6">
                <div className="flex items-center justify-between gap-2">
                    <HeadingSmall
                        title={__('Planning')}
                        description={__(
                            'Track your spending and save toward your goals',
                        )}
                    />
                    {savingsGoalsEnabled ? (
                        <CreateMenu
                            onCreate={setCreateType}
                            trigger={
                                <Button>
                                    <Plus />
                                    {__('Create')}
                                    <ChevronDown className="ml-1 h-4 w-4" />
                                </Button>
                            }
                        />
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

                {savingsGoalsEnabled && (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={filter}
                        onValueChange={changeFilter}
                        aria-label={__('Filter by type')}
                    >
                        <ToggleGroupItem
                            value="all"
                            className="cursor-pointer px-3 aria-checked:bg-primary/10"
                        >
                            {__('All')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="budgets"
                            className="cursor-pointer px-3 aria-checked:bg-primary/10"
                        >
                            {__('Budgets')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="goals"
                            className="cursor-pointer px-3 aria-checked:bg-primary/10"
                        >
                            {__('Savings Goals')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {items.map((item) =>
                        item.type === 'budget' ? (
                            <BudgetListCard
                                key={item.id}
                                budget={item.budget}
                                currencyCode={currencyCode}
                            />
                        ) : (
                            <SavingsGoalListCard
                                key={item.id}
                                savingsGoal={item.goal}
                                currencyCode={currencyCode}
                            />
                        ),
                    )}
                    <CreateCard
                        currencyCode={currencyCode}
                        savingsGoalsEnabled={savingsGoalsEnabled}
                        filter={filter}
                        isListEmpty={items.length === 0}
                        onCreate={setCreateType}
                    />
                </div>
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

interface CreateCardProps {
    currencyCode: string;
    savingsGoalsEnabled: boolean;
    filter: BudgetTypeFilter;
    isListEmpty: boolean;
    onCreate: (type: 'budget' | 'goal') => void;
}

/**
 * The card that trails the list. Without savings goals it is the budget
 * dialog's own placeholder, exactly as before. With them it offers both types
 * — unless the list is filtered, in which case it creates the type the user is
 * already looking at instead of asking again.
 */
function CreateCard({
    currencyCode,
    savingsGoalsEnabled,
    filter,
    isListEmpty,
    onCreate,
}: CreateCardProps) {
    const className = isListEmpty ? 'min-h-[260px]' : '';

    if (!savingsGoalsEnabled || filter === 'budgets') {
        return (
            <CreateBudgetDialog
                currencyCode={currencyCode}
                className={className}
            />
        );
    }

    if (filter === 'goals') {
        return (
            <CreateSavingsGoalDialog
                currencyCode={currencyCode}
                className={className}
            />
        );
    }

    return (
        <CreateMenu
            align="start"
            onCreate={onCreate}
            trigger={
                <CreatePlaceholderCard className={className}>
                    {__('Create')}
                    <ChevronDown className="ml-1 h-4 w-4" />
                </CreatePlaceholderCard>
            }
        />
    );
}

interface CreateMenuProps {
    trigger: ReactNode;
    onCreate: (type: 'budget' | 'goal') => void;
    align?: 'start' | 'end';
}

/**
 * The Budget / Savings Goal choice, offered from both the header button and
 * the list's trailing card.
 *
 * Let the menu close itself (no preventDefault, or it leaves pointer-events
 * locked on the body), and defer opening the dialog to the next frame so it
 * doesn't race the menu's close.
 */
function CreateMenu({ trigger, onCreate, align = 'end' }: CreateMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent align={align}>
                <DropdownMenuItem
                    onSelect={() =>
                        requestAnimationFrame(() => onCreate('budget'))
                    }
                >
                    {__('Budget')}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        requestAnimationFrame(() => onCreate('goal'))
                    }
                >
                    {__('Savings Goal')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
