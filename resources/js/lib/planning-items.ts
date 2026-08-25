import { Budget, budgetSeverity } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';

export type PlanningItem =
    | { type: 'budget'; id: string; name: string; budget: Budget }
    | { type: 'goal'; id: string; name: string; goal: SavingsGoal };

/**
 * How badly an item wants to be looked at. Lower sorts first.
 *
 * The tiers come straight from the status each card already shows, so the
 * ordering can never disagree with the colour the user is reading: a budget
 * past its limit, then anything close to its limit or behind schedule, then
 * everything that is fine. A savings goal cannot breach the way a budget can,
 * so it never reaches tier 0.
 */
export function planningAttentionTier(item: PlanningItem): number {
    if (item.type === 'budget') {
        const severity = budgetSeverity(item.budget);

        if (severity === 'over') {
            return 0;
        }

        return severity === 'near' ? 1 : 2;
    }

    return item.goal.stats?.status === 'behind' ? 1 : 2;
}

/**
 * Merges budgets and savings goals into the single Planning list, ordered by
 * attention and then by name. Sorting by name rather than by type is what
 * keeps the two kinds interleaved — grouping the leftovers by type would just
 * rebuild the two sections this list replaced.
 */
export function mergePlanningItems(
    budgets: Budget[],
    savingsGoals: SavingsGoal[],
    locale?: string,
): PlanningItem[] {
    const items: PlanningItem[] = [
        ...budgets.map(
            (budget): PlanningItem => ({
                type: 'budget',
                id: budget.id,
                name: budget.name,
                budget,
            }),
        ),
        ...savingsGoals.map(
            (goal): PlanningItem => ({
                type: 'goal',
                id: goal.id,
                name: goal.name,
                goal,
            }),
        ),
    ];

    return items.sort((a, b) => {
        const byTier = planningAttentionTier(a) - planningAttentionTier(b);

        return byTier !== 0 ? byTier : a.name.localeCompare(b.name, locale);
    });
}
