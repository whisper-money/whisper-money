import { withEstimateAmounts } from '@/lib/forecast/edits';
import {
    buildPlanRows,
    estimateBand,
    groupRowIds,
    type PlanRow,
} from '@/lib/forecast/plan-rows';
import { projectForecast } from '@/lib/forecast/project';
import { forecast, home } from '@/pages/forecast/forecast.fixture';
import { type ForecastGroup } from '@/types/forecast';
import { describe, expect, it } from 'vitest';

const salary: ForecastGroup = {
    ...home,
    key: 'work',
    section: 'income',
    categoryId: 'work',
    name: 'Work',
    items: [],
    estimate: {
        kind: 'income',
        basis: 'median',
        budgetAmount: null,
        amounts: { '2026-12': 300000 },
        current: null,
    },
};

const groceries: ForecastGroup = {
    ...home,
    key: 'groceries',
    categoryId: 'groceries',
    name: 'Groceries',
    items: [],
};

function ids(rows: PlanRow[]): string[] {
    return rows.map((row) => row.id);
}

describe('the rows of the plan', () => {
    const plan = {
        income: [
            salary,
            { ...salary, key: 'other', categoryId: null, name: null },
        ],
        expenses: [home, groceries],
        transfers: [],
    };

    it('lists the income categories above the expenses, then the summaries', () => {
        expect(ids(buildPlanRows(plan))).toEqual([
            'section:income',
            'group:income:work',
            'group:income:other',
            'section:expenses',
            'group:expenses:home',
            'group:expenses:groceries',
            'summary:minimum',
            'summary:close',
        ]);
    });

    it('opens only a category holding lines the user added, into them and its estimate', () => {
        const rows = buildPlanRows(plan);
        const subRows = (id: string) =>
            ids(
                (
                    rows.find((row) => row.id === id) as Extract<
                        PlanRow,
                        { type: 'group' }
                    >
                ).subRows,
            );

        expect(subRows('group:expenses:home')).toEqual([
            'line:rent',
            'line:insurance',
            'estimate:home',
        ]);
        expect(subRows('group:expenses:groceries')).toEqual([]);
        expect(subRows('group:income:work')).toEqual([]);
        expect(groupRowIds(rows)).toEqual(['group:expenses:home']);
    });
});

describe("a category's band", () => {
    it('is 40% of a spend and 10% of an income, as the projection', () => {
        expect(estimateBand('expense', -6000)).toBe(2400);
        expect(estimateBand('income', 300000)).toBe(30000);
        expect(estimateBand('income', 15)).toBe(1);
    });

    it('is the band the projection gives a month with only that estimate', () => {
        for (const [kind, amount] of [
            ['expense', 6037],
            ['income', 300017],
        ] as const) {
            const [, month] = projectForecast({
                accounts: [{ id: 'checking', openingBalance: 0 }],
                occurrences: [],
                estimates: [
                    { accountId: 'checking', month: '2027-01', amount, kind },
                ],
                startDate: '2026-12-01',
                horizonMonths: 2,
            }).accounts[0].months;

            expect(month.band).toBe(estimateBand(kind, amount));
        }
    });
});

describe('an estimate edited on the plan', () => {
    it('gives a category with only lines an estimate of its own', () => {
        const data = forecast({
            plan: {
                income: [],
                expenses: [{ ...home, estimate: null }],
                transfers: [],
            },
        });

        const edited = withEstimateAmounts(data, home, {
            '2026-11': 5000,
            '2027-01': 8000,
        });

        expect(edited.plan.expenses[0].estimate).toEqual({
            kind: 'expense',
            basis: 'adjustment',
            budgetAmount: null,
            amounts: { '2027-01': -8000 },
            current: { spent: 0, planned: 5000, left: 5000 },
        });
    });

    it('moves "Other income" up, apart from "Other spending"', () => {
        const otherIncome = { ...salary, key: 'other', categoryId: null };
        const otherSpending = { ...groceries, key: 'other', categoryId: null };
        const data = forecast({
            plan: {
                income: [otherIncome],
                expenses: [otherSpending],
                transfers: [],
            },
        });

        const edited = withEstimateAmounts(data, otherIncome, {
            '2026-12': 10000,
        });

        expect(edited.plan.income[0].estimate?.amounts['2026-12']).toBe(310000);
        expect(edited.plan.expenses[0].estimate).toEqual(
            otherSpending.estimate,
        );
    });
});
