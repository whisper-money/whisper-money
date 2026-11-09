import {
    CURRENT_MONTH,
    PREVIOUS_MONTH,
} from '../videos/cashflow-available/fixtures';
import { countUp, money } from './fixtures';

describe('money', () => {
    it('turns whole units into the minor units the app stores', () => {
        expect(money(3850)).toBe(385000);
        expect(money(12.345)).toBe(1235);
    });
});

describe('countUp', () => {
    it('scales every number and leaves the rest alone', () => {
        expect(countUp({ amount: 1000, label: 'Rent' }, 0.5)).toEqual({
            amount: 500,
            label: 'Rent',
        });
    });

    it('lands on the value itself when complete', () => {
        expect(countUp(CURRENT_MONTH, 1)).toEqual(CURRENT_MONTH);
    });
});

describe('cashflow available fixtures', () => {
    it.each([CURRENT_MONTH, PREVIOUS_MONTH])(
        'adds up like a real month would',
        (month) => {
            expect(month.net).toBe(month.income - month.expense);
            expect(
                (month.fixed_expense ?? 0) +
                    (month.variable_expense ?? 0) +
                    (month.general_expense ?? 0),
            ).toBe(month.expense);
            expect(month.savings_rate).toBeCloseTo(
                (month.net / month.income) * 100,
                1,
            );
        },
    );
});
