import { DateFormat } from '@/types/import';
import { describe, expect, it } from 'vitest';
import {
    autoDetectDateFormat,
    calculateBalancesFromTransactions,
    convertRowsToTransactions,
    getLatestTransactionDate,
    getLocaleDateFormat,
} from './file-parser';
import type { ColumnMapping, ParsedTransaction } from '@/types/import';

describe('getLocaleDateFormat', () => {
    it('returns null for undefined locale', () => {
        expect(getLocaleDateFormat(undefined)).toBeNull();
    });

    it('returns MM-DD-YYYY for en-US', () => {
        expect(getLocaleDateFormat('en-US')).toBe(DateFormat.MonthDayYear);
    });

    it('returns DD-MM-YYYY for en-GB', () => {
        expect(getLocaleDateFormat('en-GB')).toBe(DateFormat.DayMonthYear);
    });

    it('returns DD-MM-YYYY for es', () => {
        expect(getLocaleDateFormat('es')).toBe(DateFormat.DayMonthYear);
    });

    it('returns DD-MM-YYYY for de', () => {
        expect(getLocaleDateFormat('de')).toBe(DateFormat.DayMonthYear);
    });

    it('returns DD-MM-YYYY for fr', () => {
        expect(getLocaleDateFormat('fr')).toBe(DateFormat.DayMonthYear);
    });

    it('handles underscored locales like en_US', () => {
        expect(getLocaleDateFormat('en_US')).toBe(DateFormat.MonthDayYear);
    });
});

describe('convertRowsToTransactions', () => {
    it('keeps imported dates stable in timezones ahead of UTC', () => {
        const originalTimezone = process.env.TZ;
        process.env.TZ = 'Europe/Madrid';

        try {
            const transactions = convertRowsToTransactions(
                [
                    {
                        date: '04/05/2026',
                        description: 'Tarjeta Abril',
                        amount: '10.00',
                    },
                ],
                {
                    transaction_date: 'date',
                    description: 'description',
                    amount: 'amount',
                    balance: null,
                },
                DateFormat.DayMonthYear,
            );

            expect(transactions).toHaveLength(1);
            expect(transactions[0].transaction_date).toBe('2026-05-04');
        } finally {
            process.env.TZ = originalTimezone;
        }
    });
});

describe('autoDetectDateFormat', () => {
    it('returns null for empty data', () => {
        expect(autoDetectDateFormat([], 'date')).toBeNull();
    });

    it('detects YYYY-MM-DD unambiguously', () => {
        const data = [
            { date: '2024-01-15' },
            { date: '2024-02-20' },
            { date: '2024-03-25' },
        ];
        expect(autoDetectDateFormat(data, 'date')).toBe(
            DateFormat.YearMonthDay,
        );
    });

    it('detects DD-MM-YYYY when day > 12 disambiguates', () => {
        const data = [
            { date: '15/01/2024' },
            { date: '20/02/2024' },
            { date: '25/03/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date')).toBe(
            DateFormat.DayMonthYear,
        );
    });

    it('detects MM-DD-YYYY when day > 12 disambiguates', () => {
        const data = [
            { date: '01/15/2024' },
            { date: '02/20/2024' },
            { date: '03/25/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date')).toBe(
            DateFormat.MonthDayYear,
        );
    });

    it('uses locale to break tie for ambiguous dates (en-GB prefers DD-MM-YYYY)', () => {
        // All dates have day <= 12, so DD-MM-YYYY and MM-DD-YYYY both parse
        const data = [
            { date: '05/03/2024' },
            { date: '06/04/2024' },
            { date: '07/05/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date', 'en-GB')).toBe(
            DateFormat.DayMonthYear,
        );
    });

    it('uses locale to break tie for ambiguous dates (en-US prefers MM-DD-YYYY)', () => {
        const data = [
            { date: '05/03/2024' },
            { date: '06/04/2024' },
            { date: '07/05/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date', 'en-US')).toBe(
            DateFormat.MonthDayYear,
        );
    });

    it('uses locale to break tie for ambiguous dates (es prefers DD-MM-YYYY)', () => {
        const data = [
            { date: '05/03/2024' },
            { date: '06/04/2024' },
            { date: '07/05/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date', 'es')).toBe(
            DateFormat.DayMonthYear,
        );
    });

    it('prefers unambiguous detection over locale', () => {
        // Day > 12, so only DD-MM-YYYY parses correctly, even with en-US locale
        const data = [
            { date: '15/01/2024' },
            { date: '20/02/2024' },
            { date: '25/03/2024' },
        ];
        expect(autoDetectDateFormat(data, 'date', 'en-US')).toBe(
            DateFormat.DayMonthYear,
        );
    });
});

describe('getLatestTransactionDate', () => {
    const mapping: ColumnMapping = {
        transaction_date: 'date',
        description: 'desc',
        amount: 'amount',
        balance: null,
    };

    it('returns null when no date column set', () => {
        expect(
            getLatestTransactionDate(
                [{ date: '2024-01-01' }],
                { ...mapping, transaction_date: null },
                DateFormat.YearMonthDay,
            ),
        ).toBeNull();
    });

    it('returns latest date across rows in YYYY-MM-DD', () => {
        const rows = [
            { date: '2024-01-15' },
            { date: '2024-03-02' },
            { date: '2024-02-10' },
        ];
        expect(
            getLatestTransactionDate(rows, mapping, DateFormat.YearMonthDay),
        ).toBe('2024-03-02');
    });

    it('returns null when rows have no parseable date', () => {
        const rows = [{ date: '' }, { date: null }];
        expect(
            getLatestTransactionDate(rows, mapping, DateFormat.YearMonthDay),
        ).toBeNull();
    });
});

describe('calculateBalancesFromTransactions', () => {
    function txn(
        date: string,
        amount: number,
    ): ParsedTransaction {
        return {
            transaction_date: date,
            description: 'x',
            amount,
        };
    }

    it('walks balances back across distinct dates', () => {
        const txns = [
            txn('2024-01-01', 1000),
            txn('2024-01-02', -500),
            txn('2024-01-02', -200),
            txn('2024-01-03', 300),
        ];
        const balances = calculateBalancesFromTransactions(
            txns,
            '2024-01-03',
            10000,
        );
        expect(balances.get('2024-01-03')).toBe(10000);
        // before 03 net (+300): end of 02 = 9700
        expect(balances.get('2024-01-02')).toBe(9700);
        // before 02 net (-700): end of 01 = 10400
        expect(balances.get('2024-01-01')).toBe(10400);
    });

    it('handles reference date with no transactions on it', () => {
        const txns = [
            txn('2024-01-01', 1000),
            txn('2024-01-02', -200),
        ];
        const balances = calculateBalancesFromTransactions(
            txns,
            '2024-01-05',
            5000,
        );
        expect(balances.get('2024-01-05')).toBe(5000);
        expect(balances.get('2024-01-02')).toBe(5000);
        expect(balances.get('2024-01-01')).toBe(5200);
    });

    it('returns only reference when no transactions provided', () => {
        const balances = calculateBalancesFromTransactions(
            [],
            '2024-01-05',
            5000,
        );
        expect(balances.size).toBe(1);
        expect(balances.get('2024-01-05')).toBe(5000);
    });
});
