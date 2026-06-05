import type { ColumnMapping, ParsedTransaction } from '@/types/import';
import { DateFormat } from '@/types/import';
import { describe, expect, it } from 'vitest';
import {
    autoDetectColumns,
    autoDetectDateFormat,
    calculateBalancesFromTransactions,
    collectBalancesToImport,
    convertRowsToTransactions,
    detectDateFormat,
    getLatestTransactionDate,
    getLocaleDateFormat,
    parseDate,
    parseFile,
} from './file-parser';

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
                    creditor_name: null,
                    debtor_name: null,
                },
                DateFormat.DayMonthYear,
            );

            expect(transactions).toHaveLength(1);
            expect(transactions[0].transaction_date).toBe('2026-05-04');
        } finally {
            process.env.TZ = originalTimezone;
        }
    });

    it('parses YYYYMMDD compact dates', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '20241231',
                    description: 'New Year Eve',
                    amount: '10.00',
                },
            ],
            {
                transaction_date: 'date',
                description: 'description',
                amount: 'amount',
                balance: null,
                creditor_name: null,
                debtor_name: null,
            },
            DateFormat.YearMonthDayCompact,
        );

        expect(transactions).toHaveLength(1);
        expect(transactions[0].transaction_date).toBe('2024-12-31');
    });
});

describe('convertRowsToTransactions balance column', () => {
    const mapping: ColumnMapping = {
        transaction_date: 'date',
        description: 'description',
        amount: 'amount',
        balance: 'balance',
        creditor_name: null,
        debtor_name: null,
    };

    it('keeps a zero balance instead of dropping it', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'Drained account',
                    amount: '-10.00',
                    balance: 0,
                },
            ],
            mapping,
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].balance).toBe(0);
    });

    it('keeps a zero balance provided as a string', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'Drained account',
                    amount: '-10.00',
                    balance: '0',
                },
            ],
            mapping,
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].balance).toBe(0);
    });

    it('keeps a negative balance', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'Overdrawn',
                    amount: '-10.00',
                    balance: '-25.50',
                },
            ],
            mapping,
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].balance).toBe(-2550);
    });

    it('leaves balance null when the cell is empty', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'No balance',
                    amount: '-10.00',
                    balance: '',
                },
            ],
            mapping,
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].balance).toBeNull();
    });
});

describe('autoDetectColumns', () => {
    it('detects creditor and debtor name columns', () => {
        const mapping = autoDetectColumns([
            'Transaction Date',
            'Description',
            'Amount',
            'Creditor Name',
            'Debtor Name',
        ]);

        expect(mapping.creditor_name).toBe('Creditor Name');
        expect(mapping.debtor_name).toBe('Debtor Name');
    });
});

describe('convertRowsToTransactions counterparty fields', () => {
    it('maps optional creditor and debtor names', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'Transfer',
                    amount: '10.00',
                    creditor: 'Landlord LLC',
                    debtor: 'Victor Falcon',
                },
            ],
            {
                transaction_date: 'date',
                description: 'description',
                amount: 'amount',
                balance: null,
                creditor_name: 'creditor',
                debtor_name: 'debtor',
            },
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].creditor_name).toBe('Landlord LLC');
        expect(transactions[0].debtor_name).toBe('Victor Falcon');
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

    it('detects YYYYMMDD compact format unambiguously', () => {
        const data = [
            { date: '20240115' },
            { date: '20240220' },
            { date: '20240325' },
        ];
        expect(autoDetectDateFormat(data, 'date')).toBe(
            DateFormat.YearMonthDayCompact,
        );
    });
});

describe('detectDateFormat', () => {
    it('returns null for empty data', () => {
        expect(detectDateFormat([], 'date')).toBeNull();
    });

    it('flags unambiguous detection as not ambiguous', () => {
        const data = [
            { date: '15/01/2024' },
            { date: '20/02/2024' },
            { date: '25/03/2024' },
        ];
        expect(detectDateFormat(data, 'date')).toEqual({
            format: DateFormat.DayMonthYear,
            ambiguous: false,
        });
    });

    it('flags locale-resolved ties as ambiguous (en-US)', () => {
        const data = [
            { date: '05/03/2024' },
            { date: '06/04/2024' },
            { date: '07/05/2024' },
        ];
        expect(detectDateFormat(data, 'date', 'en-US')).toEqual({
            format: DateFormat.MonthDayYear,
            ambiguous: true,
        });
    });

    it('flags locale-resolved ties as ambiguous (es)', () => {
        const data = [
            { date: '02/06/2026' },
            { date: '05/03/2024' },
            { date: '07/05/2024' },
        ];
        expect(detectDateFormat(data, 'date', 'es')).toEqual({
            format: DateFormat.DayMonthYear,
            ambiguous: true,
        });
    });
});

describe('getLatestTransactionDate', () => {
    const mapping: ColumnMapping = {
        transaction_date: 'date',
        description: 'desc',
        amount: 'amount',
        balance: null,
        creditor_name: null,
        debtor_name: null,
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
    function txn(date: string, amount: number): ParsedTransaction {
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
        const txns = [txn('2024-01-01', 1000), txn('2024-01-02', -200)];
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

describe('collectBalancesToImport', () => {
    function txn(date: string, balance?: number | null): ParsedTransaction {
        return {
            transaction_date: date,
            description: 'x',
            amount: 0,
            balance,
        };
    }

    it('uses the first (newest) balance when a date repeats', () => {
        // Rows are newest-on-top; the first one holds the correct balance.
        const transactions = [
            txn('2024-01-15', 10000),
            txn('2024-01-15', 8000),
            txn('2024-01-15', 5000),
        ];

        const balances = collectBalancesToImport(transactions);

        expect(balances.get('2024-01-15')).toBe(10000);
    });

    it('keeps the first balance per date across multiple days', () => {
        const transactions = [
            txn('2024-01-16', 12000),
            txn('2024-01-15', 10000),
            txn('2024-01-15', 8000),
            txn('2024-01-14', 4000),
        ];

        const balances = collectBalancesToImport(transactions);

        expect(balances.get('2024-01-16')).toBe(12000);
        expect(balances.get('2024-01-15')).toBe(10000);
        expect(balances.get('2024-01-14')).toBe(4000);
    });

    it('keeps the first valid balance even when it is zero', () => {
        const transactions = [txn('2024-01-15', 0), txn('2024-01-15', 9000)];

        const balances = collectBalancesToImport(transactions);

        expect(balances.get('2024-01-15')).toBe(0);
    });

    it('skips transactions without a balance', () => {
        const transactions = [
            txn('2024-01-15', null),
            txn('2024-01-14', undefined),
        ];

        const balances = collectBalancesToImport(transactions);

        expect(balances.size).toBe(0);
    });
});

describe('parseFile', () => {
    it('keeps CSV date cells as their original strings instead of coercing them to date serials', async () => {
        const csv = [
            'Fecha,Concepto,Importe',
            '02/06/2026,Internet,-104576',
            '03/06/2026,Groceries,-2500',
        ].join('\n');
        const file = new File([csv], 'transactions.csv', { type: 'text/csv' });

        const { data } = await parseFile(file);

        expect(data[0].Fecha).toBe('02/06/2026');
        expect(typeof data[0].Fecha).toBe('string');

        // Because the raw string is preserved, the chosen format still drives
        // parsing: DD-MM-YYYY -> June, MM-DD-YYYY -> February.
        const asDmy = parseDate(
            data[0].Fecha as string,
            DateFormat.DayMonthYear,
        );
        const asMdy = parseDate(
            data[0].Fecha as string,
            DateFormat.MonthDayYear,
        );

        expect(asDmy?.getMonth()).toBe(5);
        expect(asMdy?.getMonth()).toBe(1);
    });
});
