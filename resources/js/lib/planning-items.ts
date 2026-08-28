import { Budget, budgetSeverity } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';

export type PlanningItem =
    | {
          type: 'budget';
          id: string;
          name: string;
          position: number | null;
          budget: Budget;
      }
    | {
          type: 'goal';
          id: string;
          name: string;
          position: number | null;
          goal: SavingsGoal;
      };

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
 * Merges budgets and savings goals into the single Planning list.
 *
 * A dragged item carries a `position` and wins outright. Everything still at
 * null — a list nobody has ever reordered, or an item created after the last
 * drag — sorts after those, by attention and then by name. Sorting by name
 * rather than by type is what keeps the two kinds interleaved; grouping the
 * leftovers by type would just rebuild the two sections this list replaced.
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
                position: budget.position ?? null,
                budget,
            }),
        ),
        ...savingsGoals.map(
            (goal): PlanningItem => ({
                type: 'goal',
                id: goal.id,
                name: goal.name,
                position: goal.position ?? null,
                goal,
            }),
        ),
    ];

    return items.sort((a, b) => {
        const positionA = a.position ?? Infinity;
        const positionB = b.position ?? Infinity;

        if (positionA !== positionB) {
            return positionA - positionB;
        }

        const byTier = planningAttentionTier(a) - planningAttentionTier(b);

        return byTier !== 0 ? byTier : a.name.localeCompare(b.name, locale);
    });
}

/**
 * Applies an id order to a list, keeping anything the order does not mention
 * in its current place at the end. Used to show a drag immediately, before the
 * server has answered with the positions it stored.
 */
export function orderPlanningItems(
    items: PlanningItem[],
    ids: string[],
): PlanningItem[] {
    const known = new Set(ids);
    const byId = new Map(items.map((item) => [item.id, item]));

    return [
        ...ids.map((id) => byId.get(id)).filter((item) => item !== undefined),
        ...items.filter((item) => !known.has(item.id)),
    ];
}

/**
 * Rebuilds the whole live order from a drag that only saw the filtered list.
 *
 * The drag returns the visible ids rearranged among themselves, so walk the
 * complete list and refill every slot that held a visible item with the next
 * id the drag produced. Hidden items keep their slots, which is what stops
 * filtering by type from pulling budgets and goals apart from each other.
 */
export function applyFilteredOrder(
    allIds: string[],
    orderedVisibleIds: string[],
): string[] {
    const visible = new Set(orderedVisibleIds);
    let next = 0;

    return allIds.map((id) =>
        visible.has(id) ? (orderedVisibleIds[next++] ?? id) : id,
    );
}
