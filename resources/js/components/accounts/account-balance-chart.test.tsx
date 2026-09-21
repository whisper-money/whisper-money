import { describe, expect, it } from 'vitest';
import {
    type BalanceDataPoint,
    chartDataForCurrencyMode,
} from './account-balance-chart';

// A GBP account read by a EUR user: every display_* twin is the raw figure at
// a 2:1 rate, so a point mixing the two currencies is obvious by inspection.
const point: BalanceDataPoint = {
    month: '2026-09',
    timestamp: 1_756_684_800,
    value: 1_000,
    invested_amount: 800,
    display_value: 2_000,
    display_invested_amount: 1_600,
};

/** Both figures share one axis and one tooltip, so both must share a currency. */
function isCoherent(p: BalanceDataPoint, rate: 1 | 2): boolean {
    return p.value === 1_000 * rate && p.invested_amount === 800 * rate;
}

// The swap used to be inverted: user mode converted the balance but left the
// invested amount account-native, and account mode did the reverse. Either way
// the chart drew two currencies on one axis and the tooltip's gain/loss
// subtracted one from the other.
describe('chartDataForCurrencyMode', () => {
    it('moves the balance and the invested amount together in user mode', () => {
        const [converted] = chartDataForCurrencyMode([point], 'user', true);

        expect(isCoherent(converted, 2)).toBe(true);
    });

    it('leaves both account-native in account mode', () => {
        const [kept] = chartDataForCurrencyMode([point], 'account', true);

        expect(isCoherent(kept, 1)).toBe(true);
    });

    it('converts nothing without a currency toggle', () => {
        const [kept] = chartDataForCurrencyMode([point], 'user', false);

        expect(isCoherent(kept, 1)).toBe(true);
    });

    it('keeps a missing invested amount missing', () => {
        const [converted] = chartDataForCurrencyMode(
            [
                {
                    ...point,
                    invested_amount: null,
                    display_invested_amount: undefined,
                },
            ],
            'user',
            true,
        );

        expect(converted.value).toBe(2_000);
        expect(converted.invested_amount).toBeNull();
    });
});
