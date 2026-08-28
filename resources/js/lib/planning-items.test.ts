import { Budget } from '@/types/budget';
import { SavingsGoal, SavingsGoalStatus } from '@/types/savings-goal';
import { describe, expect, it } from 'vitest';
import {
    applyFilteredOrder,
    mergePlanningItems,
    orderPlanningItems,
    planningAttentionTier,
    PlanningItem,
} from './planning-items';

function budget(
    name: string,
    allocated: number,
    spent: number,
    position: number | null = null,
): Budget {
    return {
        id: name,
        name,
        position,
        periods: [
            {
                allocated_amount: allocated,
                budget_transactions: [{ amount: spent }],
            },
        ],
    } as unknown as Budget;
}

function budgetWithoutPeriod(name: string): Budget {
    return { id: name, name, periods: [] } as unknown as Budget;
}

function goal(
    name: string,
    status: SavingsGoalStatus | null,
    position: number | null = null,
): SavingsGoal {
    return {
        id: name,
        name,
        position,
        stats: { status },
    } as unknown as SavingsGoal;
}

const item = (value: Budget | SavingsGoal, type: 'budget' | 'goal') =>
    (type === 'budget'
        ? { type, id: value.id, name: value.name, budget: value }
        : {
              type,
              id: value.id,
              name: value.name,
              goal: value,
          }) as PlanningItem;

describe('planningAttentionTier', () => {
    it('puts a budget past its limit first', () => {
        expect(
            planningAttentionTier(item(budget('a', 100, 120), 'budget')),
        ).toBe(0);
        expect(
            planningAttentionTier(item(budget('a', 100, 100), 'budget')),
        ).toBe(0);
    });

    it('puts a budget close to its limit in the middle tier', () => {
        expect(
            planningAttentionTier(item(budget('a', 100, 80), 'budget')),
        ).toBe(1);
        expect(
            planningAttentionTier(item(budget('a', 100, 79), 'budget')),
        ).toBe(2);
    });

    it('treats a budget with no active period as fine rather than breached', () => {
        expect(
            planningAttentionTier(item(budgetWithoutPeriod('a'), 'budget')),
        ).toBe(2);
    });

    it('never puts a savings goal in the breached tier', () => {
        expect(planningAttentionTier(item(goal('a', 'behind'), 'goal'))).toBe(
            1,
        );
        expect(planningAttentionTier(item(goal('a', 'on_track'), 'goal'))).toBe(
            2,
        );
        expect(planningAttentionTier(item(goal('a', 'ahead'), 'goal'))).toBe(2);
        expect(
            planningAttentionTier(item(goal('a', 'completed'), 'goal')),
        ).toBe(2);
        expect(planningAttentionTier(item(goal('a', null), 'goal'))).toBe(2);
    });
});

describe('mergePlanningItems', () => {
    it('orders by attention first and interleaves the two types', () => {
        const merged = mergePlanningItems(
            [budget('Groceries', 100, 85), budget('Eating out', 100, 130)],
            [goal('Japan trip', 'on_track'), goal('Emergency fund', 'behind')],
        );

        expect(merged.map((i) => i.name)).toEqual([
            'Eating out', // tier 0, over its limit
            'Emergency fund', // tier 1, behind schedule
            'Groceries', // tier 1, close to its limit
            'Japan trip', // tier 2
        ]);
    });

    it('sorts healthy items by name rather than grouping them by type', () => {
        const merged = mergePlanningItems(
            [budget('Zoo', 100, 10), budget('Bills', 100, 10)],
            [goal('Car', 'on_track'), goal('Attic', 'on_track')],
        );

        expect(merged.map((i) => i.name)).toEqual([
            'Attic',
            'Bills',
            'Car',
            'Zoo',
        ]);
    });

    it('tags each item with the type the list renders it as', () => {
        const merged = mergePlanningItems(
            [budget('Bills', 100, 10)],
            [goal('Attic', 'on_track')],
        );

        expect(merged.map((i) => i.type)).toEqual(['goal', 'budget']);
    });

    it('returns only the type it was given when the list is filtered', () => {
        expect(
            mergePlanningItems([budget('Bills', 100, 10)], []).map(
                (i) => i.type,
            ),
        ).toEqual(['budget']);
        expect(
            mergePlanningItems([], [goal('Attic', 'on_track')]).map(
                (i) => i.type,
            ),
        ).toEqual(['goal']);
        expect(mergePlanningItems([], [])).toEqual([]);
    });
});

describe('mergePlanningItems manual order', () => {
    it('keeps the attention ordering while nothing has been dragged', () => {
        const merged = mergePlanningItems(
            [budget('Groceries', 100, 85), budget('Eating out', 100, 130)],
            [goal('Japan trip', 'on_track')],
        );

        expect(merged.map((i) => i.name)).toEqual([
            'Eating out',
            'Groceries',
            'Japan trip',
        ]);
    });

    it('lets an explicit position beat the attention tier', () => {
        const merged = mergePlanningItems(
            [
                budget('Groceries', 100, 85, 1),
                budget('Eating out', 100, 130, 2),
            ],
            [goal('Japan trip', 'on_track', 0)],
        );

        expect(merged.map((i) => i.name)).toEqual([
            'Japan trip',
            'Groceries',
            'Eating out',
        ]);
    });

    it('drops a newly created item at the end of a manually ordered list', () => {
        const merged = mergePlanningItems(
            [budget('Eating out', 100, 130), budget('Groceries', 100, 10, 0)],
            [goal('Japan trip', 'on_track', 1)],
        );

        // 'Eating out' is over its limit and would normally lead the list;
        // having no position of its own puts it behind everything dragged.
        expect(merged.map((i) => i.name)).toEqual([
            'Groceries',
            'Japan trip',
            'Eating out',
        ]);
    });

    it('falls back to attention and name among the items with no position', () => {
        const merged = mergePlanningItems(
            [budget('Zoo', 100, 10), budget('Bills', 100, 130)],
            [goal('Attic', 'on_track', 0)],
        );

        expect(merged.map((i) => i.name)).toEqual(['Attic', 'Bills', 'Zoo']);
    });
});

describe('orderPlanningItems', () => {
    const items = mergePlanningItems(
        [budget('Bills', 100, 10), budget('Zoo', 100, 10)],
        [goal('Attic', 'on_track')],
    );

    it('reorders the list to follow the given ids', () => {
        expect(
            orderPlanningItems(items, ['Zoo', 'Attic', 'Bills']).map(
                (i) => i.name,
            ),
        ).toEqual(['Zoo', 'Attic', 'Bills']);
    });

    it('appends anything the order does not mention', () => {
        expect(orderPlanningItems(items, ['Zoo']).map((i) => i.name)).toEqual([
            'Zoo',
            'Attic',
            'Bills',
        ]);
    });

    it('ignores ids that are no longer in the list', () => {
        expect(
            orderPlanningItems(items, ['Gone', 'Zoo']).map((i) => i.name),
        ).toEqual(['Zoo', 'Attic', 'Bills']);
    });
});

describe('applyFilteredOrder', () => {
    it('is the drag order itself when nothing is filtered out', () => {
        expect(applyFilteredOrder(['a', 'b', 'c'], ['c', 'a', 'b'])).toEqual([
            'c',
            'a',
            'b',
        ]);
    });

    it('leaves hidden items in the slots they already had', () => {
        // b and d are hidden by the filter; a and c swap places and only the
        // slots those two occupied are refilled.
        expect(applyFilteredOrder(['a', 'b', 'c', 'd'], ['c', 'a'])).toEqual([
            'c',
            'b',
            'a',
            'd',
        ]);
    });

    it('keeps the full list even when a single item is visible', () => {
        expect(applyFilteredOrder(['a', 'b', 'c'], ['b'])).toEqual([
            'a',
            'b',
            'c',
        ]);
    });

    it('moves a visible item across hidden ones without disturbing them', () => {
        expect(
            applyFilteredOrder(
                ['b1', 'g1', 'b2', 'g2', 'b3'],
                ['b3', 'b1', 'b2'],
            ),
        ).toEqual(['b3', 'g1', 'b1', 'g2', 'b2']);
    });

    it('returns the list untouched when nothing is visible', () => {
        expect(applyFilteredOrder(['a', 'b'], [])).toEqual(['a', 'b']);
    });
});
