import { Budget } from '@/types/budget';
import { SavingsGoal, SavingsGoalStatus } from '@/types/savings-goal';
import { describe, expect, it } from 'vitest';
import {
    mergePlanningItems,
    planningAttentionTier,
    PlanningItem,
} from './planning-items';

function budget(name: string, allocated: number, spent: number): Budget {
    return {
        id: name,
        name,
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

function goal(name: string, status: SavingsGoalStatus | null): SavingsGoal {
    return {
        id: name,
        name,
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
