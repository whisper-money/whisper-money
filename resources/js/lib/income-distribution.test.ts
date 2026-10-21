import { type CashflowSummary } from '@/hooks/use-cashflow-data';
import { describe, expect, it } from 'vitest';
import { freeOf, incomeDistribution } from './income-distribution';

function summary(overrides: Partial<CashflowSummary>): CashflowSummary {
    const income = overrides.income ?? 0;
    const expense = overrides.expense ?? 0;

    return {
        income,
        expense,
        net: income - expense,
        savings_rate: 0,
        savings: 0,
        investments: 0,
        ...overrides,
    };
}

function widths(summaryOverrides: Partial<CashflowSummary>) {
    return Object.fromEntries(
        incomeDistribution(summary(summaryOverrides)).segments.map(
            (segment) => [segment.key, segment.width],
        ),
    );
}

function amounts(summaryOverrides: Partial<CashflowSummary>) {
    return Object.fromEntries(
        incomeDistribution(summary(summaryOverrides)).segments.map(
            (segment) => [segment.key, segment.amount],
        ),
    );
}

describe('incomeDistribution', () => {
    const october = {
        income: 200000,
        expense: 132000,
        fixed_expense: 94000,
        variable_expense: 38000,
        savings: 25000,
        investments: 25000,
        reserved: 13000,
    };

    it('splits income into every place it went, scaled to income', () => {
        const distribution = incomeDistribution(summary(october));

        expect(distribution.allocated).toBe(182000);
        expect(distribution.available).toBe(18000);
        expect(distribution.free).toBe(5000);
        expect(amounts(october)).toEqual({
            fixed: 94000,
            variable: 38000,
            saved: 25000,
            invested: 25000,
            reserved: 13000,
            free: 5000,
        });
        expect(widths(october)).toEqual({
            fixed: 47,
            variable: 19,
            saved: 12.5,
            invested: 12.5,
            reserved: 6.5,
            free: 2.5,
        });
        expect(distribution.allocatedWidth).toBeCloseTo(91);
        expect(distribution.availableWidth).toBeCloseTo(9);
    });

    it('scales the bar to what was allocated when that is more than income', () => {
        const overspent = {
            income: 100000,
            expense: 90000,
            fixed_expense: 60000,
            variable_expense: 30000,
            savings: 30000,
            reserved: 5000,
        };
        const distribution = incomeDistribution(summary(overspent));

        expect(distribution.available).toBe(-20000);
        expect(distribution.free).toBe(-25000);
        // 120000 allocated fills the whole bar.
        expect(widths(overspent)).toEqual({
            fixed: 50,
            variable: 25,
            saved: 25,
            invested: 0,
            reserved: 0,
            free: 0,
        });
        expect(amounts(overspent).free).toBe(-25000);
        expect(amounts(overspent).reserved).toBe(5000);
        expect(distribution.allocatedWidth).toBe(100);
        expect(distribution.availableWidth).toBe(0);
    });

    it('fits the reserve into what is available and leaves free a negative amount', () => {
        const tight = {
            income: 100000,
            expense: 95000,
            fixed_expense: 95000,
            variable_expense: 0,
            reserved: 8000,
        };

        expect(widths(tight)).toMatchObject({
            fixed: 95,
            reserved: 5,
            free: 0,
        });
        expect(amounts(tight)).toMatchObject({ reserved: 8000, free: -3000 });
    });

    it('draws nothing when there is no income and nothing allocated', () => {
        const distribution = incomeDistribution(summary({}));

        expect(distribution.segments.every((s) => s.width === 0)).toBe(true);
        expect(distribution.allocatedWidth).toBe(0);
        expect(distribution.availableWidth).toBe(0);
    });

    it('scales to the expenses when there is no income', () => {
        const noIncome = {
            expense: 5000,
            fixed_expense: 5000,
            variable_expense: 0,
        };

        expect(widths(noIncome)).toMatchObject({ fixed: 100, free: 0 });
        expect(amounts(noIncome).free).toBe(-5000);
    });

    it('gives a refund-heavy expense no width but keeps its amount', () => {
        const refunds = {
            income: 100000,
            expense: 10000,
            fixed_expense: -5000,
            variable_expense: 15000,
        };

        expect(widths(refunds)).toMatchObject({ fixed: 0 });
        expect(amounts(refunds).fixed).toBe(-5000);
        // 15000 variable + 90000 free fill the bar.
        expect(widths(refunds).variable).toBeCloseTo((15000 / 105000) * 100);
        expect(
            Object.values(widths(refunds)).reduce((a, b) => a + b, 0),
        ).toBeCloseTo(100);
    });

    it('reads every expense as fixed when the split is missing', () => {
        expect(amounts({ income: 1000, expense: 400 })).toMatchObject({
            fixed: 400,
            variable: 0,
            reserved: 0,
            free: 600,
        });
    });
});

describe('freeOf', () => {
    it('is the available minus the reserve, or the available without one', () => {
        expect(
            freeOf(summary({ income: 1000, expense: 400, reserved: 100 })),
        ).toBe(500);
        expect(freeOf(summary({ income: 1000, expense: 400 }))).toBe(600);
    });
});
