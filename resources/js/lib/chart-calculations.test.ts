import { describe, expect, it } from 'vitest';
import {
    AccountInfo,
    canUseLogScale,
    computeDeltaSeries,
    computeMoMPercent,
    computeNetWorthSeries,
    computeRolling12mChange,
    computeRolling12mPercent,
    computeWaterfallSeries,
    computeYoYPercent,
    formatCurrencyValue,
    formatPercentValue,
    getAccountSign,
    getLogScaleWarning,
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

describe('computeYoYPercent', () => {
    it('returns null for first 12 months', () => {
        const series: MonthDataPoint[] = Array.from({ length: 12 }, (_, i) => ({
            month: `2025-${String(i + 1).padStart(2, '0')}`,
            value: 10000 + i * 1000,
        }));

        const result = computeYoYPercent(series);

        result.forEach((point) => {
            expect(point.percent).toBeNull();
        });
    });

    it('computes correct YoY percentage after 12 months', () => {
        const series: MonthDataPoint[] = Array.from({ length: 13 }, (_, i) => ({
            month: `${2024 + Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`,
            value: i === 0 ? 10000 : i === 12 ? 12000 : 10000 + i * 100,
        }));

        const result = computeYoYPercent(series);

        expect(result[12].percent).toBe(20); // (12000 - 10000) / 10000 * 100 = 20%
    });

    it('returns null when year-ago value is zero', () => {
        const series: MonthDataPoint[] = Array.from({ length: 13 }, (_, i) => ({
            month: `${2024 + Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`,
            value: i === 0 ? 0 : 10000,
        }));

        const result = computeYoYPercent(series);

        expect(result[12].percent).toBeNull();
    });
});

describe('computeRolling12mChange', () => {
    it('returns null for first 12 months', () => {
        const series: MonthDataPoint[] = Array.from({ length: 12 }, (_, i) => ({
            month: `2025-${String(i + 1).padStart(2, '0')}`,
            value: 10000,
        }));

        const result = computeRolling12mChange(series);

        result.forEach((point) => {
            expect(point.change).toBeNull();
        });
    });

    it('computes correct rolling 12m change', () => {
        const series: MonthDataPoint[] = Array.from({ length: 13 }, (_, i) => ({
            month: `${2024 + Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`,
            value: i === 0 ? 100000 : i === 12 ? 150000 : 110000,
        }));

        const result = computeRolling12mChange(series);

        expect(result[12].change).toBe(50000); // 150000 - 100000
    });
});

describe('computeRolling12mPercent', () => {
    it('returns null for first 12 months', () => {
        const series: MonthDataPoint[] = Array.from({ length: 12 }, (_, i) => ({
            month: `2025-${String(i + 1).padStart(2, '0')}`,
            value: 10000,
        }));

        const result = computeRolling12mPercent(series);

        result.forEach((point) => {
            expect(point.percent).toBeNull();
        });
    });

    it('computes correct rolling 12m percentage', () => {
        const series: MonthDataPoint[] = Array.from({ length: 13 }, (_, i) => ({
            month: `${2024 + Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`,
            value: i === 0 ? 100000 : i === 12 ? 150000 : 110000,
        }));

        const result = computeRolling12mPercent(series);

        expect(result[12].percent).toBe(50); // 50% increase
    });
});

describe('computeWaterfallSeries', () => {
    it('returns empty array for series with less than 2 points', () => {
        const series: MonthDataPoint[] = [{ month: '2025-01', value: 10000 }];

        const result = computeWaterfallSeries(series);

        expect(result).toEqual([]);
    });

    it('computes waterfall for last month transition by default', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 100000 },
            { month: '2025-02', value: 120000 },
        ];

        const result = computeWaterfallSeries(series);

        expect(result).toHaveLength(3);
        expect(result[0]).toEqual({
            name: 'Start',
            value: 100000,
            type: 'start',
            month: '2025-01',
        });
        expect(result[1]).toEqual({
            name: 'Change',
            value: 20000,
            type: 'change',
            month: '2025-02',
        });
        expect(result[2]).toEqual({
            name: 'End',
            value: 120000,
            type: 'end',
            month: '2025-02',
        });
    });

    it('maintains waterfall identity: Start + Change = End', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 100000 },
            { month: '2025-02', value: 85000 },
        ];

        const result = computeWaterfallSeries(series);

        const start = result.find((p) => p.type === 'start')!.value;
        const change = result.find((p) => p.type === 'change')!.value;
        const end = result.find((p) => p.type === 'end')!.value;

        expect(start + change).toBe(end);
    });

    it('handles negative change correctly', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 100000 },
            { month: '2025-02', value: 80000 },
        ];

        const result = computeWaterfallSeries(series);

        expect(result[1].value).toBe(-20000);
    });

    it('allows specifying month index', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 100000 },
            { month: '2025-02', value: 110000 },
            { month: '2025-03', value: 130000 },
        ];

        const result = computeWaterfallSeries(series, 1);

        expect(result[0].month).toBe('2025-01');
        expect(result[2].month).toBe('2025-02');
    });

    it('returns empty array for invalid month index', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 100000 },
            { month: '2025-02', value: 110000 },
        ];

        expect(computeWaterfallSeries(series, 0)).toEqual([]);
        expect(computeWaterfallSeries(series, 5)).toEqual([]);
    });
});

describe('canUseLogScale', () => {
    it('returns false for empty series', () => {
        expect(canUseLogScale([])).toBe(false);
    });

    it('returns true when all values are positive', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 10000 },
            { month: '2025-02', value: 20000 },
        ];

        expect(canUseLogScale(series)).toBe(true);
    });

    it('returns false when any value is zero', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 0 },
            { month: '2025-02', value: 20000 },
        ];

        expect(canUseLogScale(series)).toBe(false);
    });

    it('returns false when any value is negative', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: -10000 },
            { month: '2025-02', value: 20000 },
        ];

        expect(canUseLogScale(series)).toBe(false);
    });
});

describe('getLogScaleWarning', () => {
    it('returns message for empty series', () => {
        expect(getLogScaleWarning([])).toBe('No data available');
    });

    it('returns null when log scale can be used', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 10000 },
            { month: '2025-02', value: 20000 },
        ];

        expect(getLogScaleWarning(series)).toBeNull();
    });

    it('returns appropriate message for negative values', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: -10000 },
            { month: '2025-02', value: 20000 },
        ];

        const warning = getLogScaleWarning(series);

        expect(warning).toContain('negative');
    });

    it('returns appropriate message for zero values', () => {
        const series: MonthDataPoint[] = [
            { month: '2025-01', value: 0 },
            { month: '2025-02', value: 20000 },
        ];

        const warning = getLogScaleWarning(series);

        expect(warning).toContain('zero');
    });
});

describe('formatCurrencyValue', () => {
    it('formats EUR correctly', () => {
        const result = formatCurrencyValue(150000, 'EUR');

        expect(result).toMatch(/1[,.]?500/); // Handles different locale formats
    });

    it('formats USD correctly', () => {
        const result = formatCurrencyValue(100000, 'USD');

        expect(result).toContain('$');
        expect(result).toMatch(/1[,.]?000/);
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
