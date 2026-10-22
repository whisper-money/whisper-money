import { type CashflowSummary } from '@/hooks/use-cashflow-data';
import { describe, expect, it } from 'vitest';
import { allocatedOf, availableOf, freeOf } from './cashflow-available';

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

describe('cashflow available', () => {
    const october = summary({
        income: 200000,
        expense: 132000,
        savings: 25000,
        investments: 25000,
        reserved: 13000,
    });

    it('allocates expenses plus what was saved or invested', () => {
        expect(allocatedOf(october)).toBe(182000);
    });

    it('leaves as available what income has left after the allocation', () => {
        expect(availableOf(october)).toBe(18000);
    });

    it('goes negative when more was allocated than came in', () => {
        expect(availableOf(summary({ income: 1000, expense: 1500 }))).toBe(
            -500,
        );
    });

    it('frees what is available once the budgets reserve is set aside', () => {
        expect(freeOf(october)).toBe(5000);
    });

    it('frees the whole available when nothing is reserved', () => {
        expect(freeOf({ ...october, reserved: undefined })).toBe(18000);
    });
});
