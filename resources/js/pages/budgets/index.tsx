import {
    index,
    reorder,
} from '@/actions/App/Http/Controllers/BudgetController';
import { BudgetListCard } from '@/components/budgets/budget-list-card';
import { CreateBudgetDialog } from '@/components/budgets/create-budget-dialog';
import HeadingSmall from '@/components/heading-small';
import { CreateSavingsGoalDialog } from '@/components/savings-goals/create-savings-goal-dialog';
import { SavingsGoalListCard } from '@/components/savings-goals/savings-goal-list-card';
import { CreatePlaceholderCard } from '@/components/shared/create-placeholder-card';
import { PlanningReorderDialog } from '@/components/shared/planning-reorder-dialog';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useLocale } from '@/hooks/use-locale';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import {
    applyFilteredOrder,
    mergePlanningItems,
    orderPlanningItems,
    PlanningItem,
} from '@/lib/planning-items';
import { BreadcrumbItem } from '@/types';
import { Budget } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Pencil, Plus } from 'lucide-react';
import { ReactNode, useCallback, useMemo, useState } from 'react';

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
    currencyCode: string;
}

export default function BudgetsIndex({
    budgets,
    savingsGoals = [],
    currencyCode,
}: Props) {
    const [createType, setCreateType] = useState<'budget' | 'goal' | null>(
        null,
    );
    const [archivedOpen, setArchivedOpen] = useState(false);
    const [reorderOpen, setReorderOpen] = useState(false);
    // Optimistic ordering layered on top of the server order. Null means "use
    // the server order"; a drag sets the new live-item order and persists it.
    const [order, setOrder] = useState<string[] | null>(null);
    const { url } = usePage();
    const locale = useLocale();
    const [filter, setFilter] = useState<BudgetTypeFilter>(() =>
        budgetTypeFilterFromUrl(url),
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

    // Budgets and savings goals share one list: a manual position leads, and
    // otherwise whichever needs attention first — neither type is stuck below
    // the other.
    //
    // Archived ones arrive in the same props and are split off here into their
    // own collapsed section, ordered by the same rule so the two lists cannot
    // disagree about how they sort. They are not reorderable.
    const { liveItems, items, archivedItems } = useMemo(() => {
        const merge = (archived: boolean) =>
            mergePlanningItems(
                budgets.filter((budget) => !!budget.archived_at === archived),
                savingsGoals.filter((goal) => !!goal.archived_at === archived),
                locale,
            );

        const live = merge(false);
        const ordered = order ? orderPlanningItems(live, order) : live;
        const matchesFilter = (item: PlanningItem) =>
            filter === 'all' ||
            item.type === (filter === 'budgets' ? 'budget' : 'goal');

        return {
            liveItems: ordered,
            items: ordered.filter(matchesFilter),
            archivedItems: merge(true).filter(matchesFilter),
        };
    }, [budgets, savingsGoals, filter, locale, order]);

    const handleReorder = useCallback(
        (orderedVisibleIds: string[]) => {
            // The dialog only lists what the filter left on screen, so the
            // full order is rebuilt around it and the whole live list is
            // persisted — never just the filtered subset, or the hidden items
            // would lose their slots and the two types would drift apart.
            const nextOrder = applyFilteredOrder(
                liveItems.map((item) => item.id),
                orderedVisibleIds,
            );
            setOrder(nextOrder);

            const typeById = new Map(
                liveItems.map((item) => [item.id, item.type]),
            );
            router.patch(
                reorder.url(),
                {
                    items: nextOrder.map((id) => ({
                        id,
                        type: typeById.get(id),
                    })),
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['budgets', 'savingsGoals'],
                },
            );
        },
        [liveItems],
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
                </div>

                {/* The row wraps rather than shrinks: the filter is already as
                    narrow as its labels allow, so on a phone the reorder
                    button drops to its own line instead of sitting on top of
                    it. */}
                <div className="flex flex-wrap items-center gap-2">
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
                    {items.length > 1 && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="ml-auto"
                            onClick={() => setReorderOpen(true)}
                            aria-label={__('Edit order')}
                        >
                            <Pencil className="size-4" />
                            <span className="hidden md:inline">
                                {__('Edit order')}
                            </span>
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <PlanningCards items={items} currencyCode={currencyCode} />
                    <CreateCard
                        currencyCode={currencyCode}
                        filter={filter}
                        isListEmpty={items.length === 0}
                        onCreate={setCreateType}
                    />
                </div>

                {archivedItems.length > 0 && (
                    <Collapsible
                        open={archivedOpen}
                        onOpenChange={setArchivedOpen}
                        className="space-y-4"
                    >
                        <CollapsibleTrigger className="flex cursor-pointer items-center gap-2 text-lg font-medium text-muted-foreground">
                            {archivedOpen ? (
                                <ChevronDown className="h-4 w-4" />
                            ) : (
                                <ChevronRight className="h-4 w-4" />
                            )}
                            {__('Archived (:count)', {
                                count: archivedItems.length,
                            })}
                        </CollapsibleTrigger>
                        <CollapsibleContent className="grid gap-4 lg:grid-cols-2">
                            <PlanningCards
                                items={archivedItems}
                                currencyCode={currencyCode}
                            />
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>

            <PlanningReorderDialog
                open={reorderOpen}
                onOpenChange={setReorderOpen}
                items={items}
                onReorder={handleReorder}
            />

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
        </AppSidebarLayout>
    );
}

/**
 * The cards themselves, picked by type. Both the live list and the archived
 * section render the same grid, so the mapping lives in one place.
 */
function PlanningCards({
    items,
    currencyCode,
}: {
    items: PlanningItem[];
    currencyCode: string;
}) {
    return items.map((item) =>
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
    );
}

interface CreateCardProps {
    currencyCode: string;
    filter: BudgetTypeFilter;
    isListEmpty: boolean;
    onCreate: (type: 'budget' | 'goal') => void;
}

/**
 * The card that trails the list. It offers both types — unless the list is
 * filtered, in which case it creates the type the user is already looking at
 * instead of asking again.
 */
function CreateCard({
    currencyCode,
    filter,
    isListEmpty,
    onCreate,
}: CreateCardProps) {
    const className = isListEmpty ? 'min-h-[260px]' : '';

    if (filter === 'budgets') {
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
