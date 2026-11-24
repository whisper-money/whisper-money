import { describe, expect, it } from 'vitest';
import { calculateNetWorthTrend, firstKnownIndex } from './net-worth-history';

const accounts = {
    checking: {
        id: 'checking',
        type: 'checking' as const,
        currency_code: 'EUR',
    },
    savings: { id: 'savings', type: 'savings' as const, currency_code: 'EUR' },
};

const months = (values: Array<[number | null, number | null]>) =>
    values.map(([checking, savings], index) => ({
        month: `2026-${String(index + 1).padStart(2, '0')}`,
        checking,
        savings,
    }));

describe('firstKnownIndex', () => {
    it('finds the first period where any account has a figure', () => {
        const data = months([
            [null, null],
            [null, 100],
            [200, 100],
        ]);

        expect(firstKnownIndex(data, ['checking', 'savings'])).toBe(1);
        expect(firstKnownIndex(data, ['checking'])).toBe(2);
    });

    it('is -1 when nothing is known', () => {
        expect(firstKnownIndex(months([[null, null]]), ['checking'])).toBe(-1);
    });
});

describe('calculateNetWorthTrend', () => {
    it('measures a full history from the start of the range', () => {
        const data = months([
            [100, 0],
            [150, 0],
            [200, 0],
        ]);

        expect(calculateNetWorthTrend(data, accounts, 2)).toEqual({
            percentage: 100,
            previousAmount: 100,
            currentAmount: 200,
        });
    });

    it('measures a shorter history from its first period with data', () => {
        const data = months([
            [null, null],
            [null, null],
            [100, null],
            [150, null],
        ]);

        expect(calculateNetWorthTrend(data, accounts, 3)).toEqual({
            percentage: 50,
            previousAmount: 100,
            currentAmount: 150,
        });
    });

    it('has no trend when the period it compares with is unknown', () => {
        const data = months([
            [null, null],
            [100, null],
        ]);

        expect(calculateNetWorthTrend(data, accounts, 1)).toBeNull();
    });

    it('has no trend when nothing is known', () => {
        expect(
            calculateNetWorthTrend(months([[null, null]]), accounts, 1),
        ).toBeNull();
    });
});
