import { describe, expect, it } from 'vitest';
import {
    AccountInfo,
    computeDeltaSeries,
    computeMoMPercent,
    computeNetWorthSeries,
    formatPercentValue,
    getAccountSign,
    isLiabilityType,
    MonthDataPoint,
} from './chart-calculations';

describe('isLiabilityType', () => {
    it('returns true for credit_card', () => {
        expect(isLiabilityType('credit_card')).toBe(true);
    });

    it('returns true for loan', () => {
        expect(isLiabilityType('loan')).toBe(true);
    });

    it('returns false for checking', () => {
        expect(isLiabilityType('checking')).toBe(false);
    });

    it('returns false for savings', () => {
        expect(isLiabilityType('savings')).toBe(false);
    });

    it('returns false for investment', () => {
        expect(isLiabilityType('investment')).toBe(false);
    });

    it('returns false for retirement', () => {
        expect(isLiabilityType('retirement')).toBe(false);
    });

    it('returns false for others', () => {
        expect(isLiabilityType('others')).toBe(false);
    });
});

describe('getAccountSign', () => {
    it('returns -1 for liabilities', () => {
        expect(getAccountSign('credit_card')).toBe(-1);
        expect(getAccountSign('loan')).toBe(-1);
    });

    it('returns 1 for assets', () => {
        expect(getAccountSign('checking')).toBe(1);
        expect(getAccountSign('savings')).toBe(1);
        expect(getAccountSign('investment')).toBe(1);
        expect(getAccountSign('retirement')).toBe(1);
        expect(getAccountSign('others')).toBe(1);
    });
});

describe('computeNetWorthSeries', () => {
    const createAccounts = (
        types: Record<string, 'checking' | 'savings' | 'credit_card' | 'loan'>,
    ): Record<string, AccountInfo> => {
        const accounts: Record<string, AccountInfo> = {};
        for (const [id, type] of Object.entries(types)) {
            accounts[id] = { id, type, currency_code: 'EUR' };
        }
        return accounts;
    };

    it('returns empty array for empty data', () => {
        const result = computeNetWorthSeries([], {});
        expect(result).toEqual([]);
    });

    it('sums assets correctly', () => {
        const data = [
            { month: '2025-01', acc1: 10000, acc2: 20000 },
            { month: '2025-02', acc1: 15000, acc2: 25000 },
        ];
        const accounts = createAccounts({ acc1: 'checking', acc2: 'savings' });

        const result = computeNetWorthSeries(data, accounts);

        expect(result).toHaveLength(2);
        expect(result[0]).toEqual({ month: '2025-01', value: 30000 });
        expect(result[1]).toEqual({ month: '2025-02', value: 40000 });
    });

    it('subtracts liabilities from net worth', () => {
        const data = [
            { month: '2025-01', checking: 50000, credit_card: 10000 },
        ];
        const accounts = createAccounts({
            checking: 'checking',
            credit_card: 'credit_card',
        });

        const result = computeNetWorthSeries(data, accounts);

        expect(result[0].value).toBe(40000); // 50000 - 10000
    });

    it('handles mixed assets and liabilities', () => {
        const data = [
            {
                month: '2025-01',
                savings: 100000,
                checking: 20000,
                credit_card: 5000,
                loan: 30000,
            },
        ];
        const accounts = createAccounts({
            savings: 'savings',
            checking: 'checking',
            credit_card: 'credit_card',
            loan: 'loan',
        });

        const result = computeNetWorthSeries(data, accounts);

        // Net worth = 100000 + 20000 - 5000 - 30000 = 85000
        expect(result[0].value).toBe(85000);
    });

    it('handles negative net worth (more liabilities than assets)', () => {
        const data = [{ month: '2025-01', checking: 10000, loan: 50000 }];
        const accounts = createAccounts({ checking: 'checking', loan: 'loan' });

        const result = computeNetWorthSeries(data, accounts);

        expect(result[0].value).toBe(-40000); // 10000 - 50000
    });

    it('preserves timestamp if present', () => {
        const data = [{ month: '2025-01', timestamp: 1704067200, acc1: 10000 }];
        const accounts = createAccounts({ acc1: 'checking' });

        const result = computeNetWorthSeries(data, accounts);

        expect(result[0].timestamp).toBe(1704067200);
    });

    it('skips non-numeric balance values', () => {
        const data = [{ month: '2025-01', acc1: 10000, acc2: 'invalid' }];
        const accounts = createAccounts({ acc1: 'checking', acc2: 'savings' });

        const result = computeNetWorthSeries(data, accounts);

        expect(result[0].value).toBe(10000);
    });
});

describe('computeDeltaSeries', () => {
    it('returns null change for first month', () => {
        const series: MonthDataPoint[] = [{ month: '2025-01', value: 10000 }];

        const result = computeDeltaSeries(series);

        expect(result[0].change).toBeNull();
    });

    it('computes correct positive delta', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 10000 },
            { month: '2025-02', value: 15000 },
        ];

        const result = computeDeltaSeries(series);

        expect(result[1].change).toBe(5000);
    });

    it('computes correct negative delta', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 15000 },
            { month: '2025-02', value: 10000 },
        ];

        const result = computeDeltaSeries(series);

        expect(result[1].change).toBe(-5000);
    });
});

describe('computeMoMPercent', () => {
    it('returns null for first month', () => {
        const series: MonthDataPoint[] = [{ month: '2025-01', value: 10000 }];

        const result = computeMoMPercent(series);

        expect(result[0].percent).toBeNull();
    });

    it('computes correct positive percentage', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 10000 },
            { month: '2025-02', value: 11000 },
        ];

        const result = computeMoMPercent(series);

        expect(result[1].percent).toBe(10); // +10%
    });

    it('computes correct negative percentage', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 10000 },
            { month: '2025-02', value: 9000 },
        ];

        const result = computeMoMPercent(series);

        expect(result[1].percent).toBe(-10); // -10%
    });

    it('returns null when previous value is zero (division by zero guard)', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 0 },
            { month: '2025-02', value: 10000 },
        ];

        const result = computeMoMPercent(series);

        expect(result[1].percent).toBeNull();
    });

    it('handles negative to positive transition correctly', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: -10000 },
            { month: '2025-02', value: 5000 },
        ];

        const result = computeMoMPercent(series);

        // Change of 15000 relative to absolute value of -10000 = 150%
        expect(result[1].percent).toBe(150);
    });
});

describe('edge cases for slice(1) in useChartViews hook', () => {
    // The hook slices off the first month (needed for MoM calculation baseline)
    // These tests document expected behavior for edge cases

    it('single month data results in empty series after slice', () => {
        const data = [{ month: '2025-01', acc1: 10000 }];
        const accounts: Record<string, AccountInfo> = {
            acc1: { id: 'acc1', type: 'checking', currency_code: 'EUR' },
        };

        const netWorth = computeNetWorthSeries(data, accounts);
        const delta = computeDeltaSeries(netWorth);
        const momPercent = computeMoMPercent(netWorth);

        // All series have 1 element before slicing
        expect(netWorth).toHaveLength(1);
        expect(delta).toHaveLength(1);
        expect(momPercent).toHaveLength(1);

        // After slice(1), all become empty
        expect(netWorth.slice(1)).toHaveLength(0);
        expect(delta.slice(1)).toHaveLength(0);
        expect(momPercent.slice(1)).toHaveLength(0);
    });

    it('two months data results in single data point after slice', () => {
        const data = [
            { month: '2025-01', acc1: 10000 },
            { month: '2025-02', acc1: 15000 },
        ];
        const accounts: Record<string, AccountInfo> = {
            acc1: { id: 'acc1', type: 'checking', currency_code: 'EUR' },
        };

        const netWorth = computeNetWorthSeries(data, accounts);
        const delta = computeDeltaSeries(netWorth);
        const momPercent = computeMoMPercent(netWorth);

        // After slice(1), we have 1 displayable data point
        expect(netWorth.slice(1)).toHaveLength(1);
        expect(delta.slice(1)).toHaveLength(1);
        expect(momPercent.slice(1)).toHaveLength(1);

        // And the values are computed correctly
        expect(netWorth.slice(1)[0].value).toBe(15000);
        expect(delta.slice(1)[0].change).toBe(5000);
        expect(momPercent.slice(1)[0].percent).toBe(50);
    });

    it('empty data results in empty series', () => {
        const netWorth = computeNetWorthSeries([], {});
        const delta = computeDeltaSeries(netWorth);
        const momPercent = computeMoMPercent(netWorth);

        expect(netWorth.slice(1)).toHaveLength(0);
        expect(delta.slice(1)).toHaveLength(0);
        expect(momPercent.slice(1)).toHaveLength(0);
    });
});

describe('formatPercentValue', () => {
    it('returns dash for null values', () => {
        expect(formatPercentValue(null)).toBe('—');
    });

    it('adds plus sign for positive values', () => {
        expect(formatPercentValue(10.5)).toBe('+10.5%');
    });

    it('does not add sign for negative values', () => {
        expect(formatPercentValue(-5.3)).toBe('-5.3%');
    });

    it('formats zero without sign', () => {
        expect(formatPercentValue(0)).toBe('0.0%');
    });

    it('respects custom decimal places', () => {
        expect(formatPercentValue(10.567, { decimals: 2 })).toBe('+10.57%');
    });
});
