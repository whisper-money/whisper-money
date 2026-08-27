import type { ColumnMapping, ParsedTransaction } from '@/types/import';
import { DateFormat } from '@/types/import';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    autoDetectColumns,
    autoDetectDateFormat,
    buildMappingReport,
    calculateBalancesFromTransactions,
    collectBalancesToImport,
    collectCurrencyCodes,
    convertRowsToTransactions,
    detectDateFormat,
    formatLocalDate,
    getLatestTransactionDate,
    getLocaleDateFormat,
    isInAccountCurrency,
    parseAmount,
    parseCurrencyCode,
    parseDate,
    parseFile,
} from './file-parser';

/**
 * A spreadsheet Numbers itself saved, with three rows and a real date column.
 * The preset image fills and previews Numbers bundles in were stripped to keep
 * it small; the Index/*.iwa archives holding the data are untouched.
 */
function numbersFixture(): Uint8Array<ArrayBuffer> {
    return new Uint8Array(
        readFileSync(
            'resources/js/lib/__fixtures__/numbers-with-date-cells.numbers',
        ),
    );
}

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
                    currency: null,
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
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            },
            DateFormat.YearMonthDayCompact,
        );

        expect(transactions).toHaveLength(1);
        expect(transactions[0].transaction_date).toBe('2024-12-31');
    });

    it('stores an amount with a trailing sign as an expense', () => {
        const transactions = convertRowsToTransactions(
            [
                {
                    date: '2026-05-04',
                    description: 'Card purchase',
                    amount: '10,32-',
                },
            ],
            {
                transaction_date: 'date',
                description: 'description',
                amount: 'amount',
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            },
            DateFormat.YearMonthDay,
        );

        expect(transactions[0].amount).toBe(-1032);
    });
});

describe('convertRowsToTransactions balance column', () => {
    const mapping: ColumnMapping = {
        transaction_date: 'date',
        description: 'description',
        amount: 'amount',
        currency: null,
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
                    currency: null,
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
                    currency: null,
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
                    currency: null,
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
                    currency: null,
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

    it('maps an English header set', () => {
        const mapping = autoDetectColumns([
            'Transaction Date',
            'Description',
            'Amount',
        ]);

        expect(mapping.transaction_date).toBe('Transaction Date');
        expect(mapping.description).toBe('Description');
        expect(mapping.amount).toBe('Amount');
    });

    it('maps a Spanish header set', () => {
        const mapping = autoDetectColumns(['Fecha', 'Concepto', 'Importe']);

        expect(mapping.transaction_date).toBe('Fecha');
        expect(mapping.description).toBe('Concepto');
        expect(mapping.amount).toBe('Importe');
    });

    it('prefers Importe over a date column matching "valor"', () => {
        const mapping = autoDetectColumns([
            'Fecha operación',
            'Fecha valor',
            'Concepto',
            'Importe',
            'Saldo',
        ]);

        expect(mapping.transaction_date).toBe('Fecha operación');
        expect(mapping.description).toBe('Concepto');
        expect(mapping.amount).toBe('Importe');
        expect(mapping.balance).toBe('Saldo');
    });

    it('does not claim the date column as the amount column', () => {
        const mapping = autoDetectColumns([
            'Fecha valor',
            'Concepto',
            'Importe',
        ]);

        expect(mapping.transaction_date).toBe('Fecha valor');
        expect(mapping.amount).toBe('Importe');
    });

    it('leaves the amount unmapped rather than taking a losing date column', () => {
        const mapping = autoDetectColumns([
            'Fecha operación',
            'Fecha valor',
            'Concepto',
            'Saldo',
        ]);

        expect(mapping.transaction_date).toBe('Fecha operación');
        expect(mapping.balance).toBe('Saldo');
        expect(mapping.amount).toBeNull();
    });

    it('reads "Saldo total" as a balance rather than an amount', () => {
        const mapping = autoDetectColumns(['Fecha', 'Concepto', 'Saldo total']);

        expect(mapping.balance).toBe('Saldo total');
        expect(mapping.amount).toBeNull();
    });

    it('keeps Valor as the amount column when it is the only match', () => {
        const mapping = autoDetectColumns(['Data', 'Descrição', 'Valor']);

        expect(mapping.description).toBe('Descrição');
        expect(mapping.amount).toBe('Valor');
    });
});

describe('parseAmount', () => {
    it('keeps the sign for the layouts that already worked', () => {
        expect(parseAmount('-50,32')).toBe(-50.32);
        expect(parseAmount('-50,32 €')).toBe(-50.32);
        expect(parseAmount('(50,32)')).toBe(-50.32);
        expect(parseAmount('- 50,32')).toBe(-50.32);
        expect(parseAmount('-1.234,56')).toBe(-1234.56);
    });

    it('detects a sign written after the currency symbol or code', () => {
        expect(parseAmount('€ -50,32')).toBe(-50.32);
        expect(parseAmount('EUR -50,32')).toBe(-50.32);
    });

    it('detects a trailing sign', () => {
        expect(parseAmount('50,32-')).toBe(-50.32);
        expect(parseAmount('1.234,56-')).toBe(-1234.56);
    });

    it('detects the unicode minus sign and the en dash', () => {
        expect(parseAmount('\u221250,32')).toBe(-50.32);
        expect(parseAmount('\u2013 50,32')).toBe(-50.32);
    });

    it('detects a sign wrapped in quotes', () => {
        expect(parseAmount('"-50,32"')).toBe(-50.32);
    });

    it('does not read hyphens between digits as a negative sign', () => {
        expect(parseAmount('31-07-2026')).toBe(31072026);
        expect(parseAmount('2026-07-31')).toBe(20260731);
        expect(parseAmount('50,32')).toBe(50.32);
    });

    it('leaves numeric cells untouched', () => {
        expect(parseAmount(-50.32)).toBe(-50.32);
        expect(parseAmount(50.32)).toBe(50.32);
    });

    it('returns null when there is no number to parse', () => {
        expect(parseAmount('')).toBeNull();
        expect(parseAmount('-')).toBeNull();
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
                currency: null,
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
        currency: null,
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

describe('currency mapping', () => {
    const SUPPORTED = ['EUR', 'USD', 'PEN'];

    const mapping: ColumnMapping = {
        transaction_date: 'Date',
        description: 'Note',
        amount: 'Amount',
        currency: 'Currency',
        balance: null,
        creditor_name: null,
        debtor_name: null,
    };

    function row(currency: string | null) {
        return {
            Date: '2018-05-14',
            Note: 'Camara',
            Amount: '-396.00',
            Currency: currency,
        };
    }

    it('normalises the code found in the mapped column', () => {
        expect(parseCurrencyCode(' pen ', SUPPORTED)).toBe('PEN');
    });

    it('rejects values that are not a three-letter code', () => {
        expect(parseCurrencyCode('S/.', SUPPORTED)).toBeNull();
        expect(parseCurrencyCode('', SUPPORTED)).toBeNull();
        expect(parseCurrencyCode(null, SUPPORTED)).toBeNull();
    });

    it('rejects codes the app does not support', () => {
        expect(parseCurrencyCode('XAU', SUPPORTED)).toBeNull();
        expect(parseCurrencyCode('XAU')).toBe('XAU');
    });

    it('detects a currency column by header name', () => {
        expect(
            autoDetectColumns(['Date', 'Amount', 'Currency', 'Note']).currency,
        ).toBe('Currency');
        expect(autoDetectColumns(['Fecha', 'Importe', 'Moneda']).currency).toBe(
            'Moneda',
        );
    });

    it('does not invent a currency column when there is none', () => {
        expect(
            autoDetectColumns(['Date', 'Amount', 'Note']).currency,
        ).toBeNull();
    });

    it('keeps each row currency when a column is mapped', () => {
        const transactions = convertRowsToTransactions(
            [row('PEN'), row('USD')],
            mapping,
            DateFormat.YearMonthDay,
            SUPPORTED,
        );

        expect(transactions.map((t) => t.currency_code)).toEqual([
            'PEN',
            'USD',
        ]);
    });

    it('falls back to the account currency for unmapped or unusable values', () => {
        const transactions = convertRowsToTransactions(
            [row(null), row('S/.'), row('XAU')],
            mapping,
            DateFormat.YearMonthDay,
            SUPPORTED,
        );

        expect(transactions.map((t) => t.currency_code)).toEqual([
            null,
            null,
            null,
        ]);
    });

    it('leaves the currency unset when no column is mapped', () => {
        const transactions = convertRowsToTransactions(
            [row('PEN')],
            { ...mapping, currency: null },
            DateFormat.YearMonthDay,
            SUPPORTED,
        );

        expect(transactions[0].currency_code).toBeNull();
    });

    it('splits the codes found in a column into supported and unsupported', () => {
        const found = collectCurrencyCodes(
            [row('PEN'), row('pen'), row('XAU'), row('S/.'), row(null)],
            'Currency',
            SUPPORTED,
        );

        expect(found.supported).toEqual(['PEN']);
        expect(found.unsupported).toEqual(['XAU', 'S/.']);
    });

    it('finds no codes when no column is mapped', () => {
        expect(collectCurrencyCodes([row('PEN')], null, SUPPORTED)).toEqual({
            supported: [],
            unsupported: [],
        });
    });
});

describe('isInAccountCurrency', () => {
    function txn(currencyCode: string | null): ParsedTransaction {
        return {
            transaction_date: '2024-01-15',
            description: 'x',
            amount: 100,
            currency_code: currencyCode,
        };
    }

    it('counts a row with no currency of its own as the account currency', () => {
        expect(isInAccountCurrency(txn(null), 'EUR')).toBe(true);
    });

    it('counts a row that names the account currency', () => {
        expect(isInAccountCurrency(txn('EUR'), 'EUR')).toBe(true);
    });

    it('rejects a row held in another currency', () => {
        expect(isInAccountCurrency(txn('PEN'), 'EUR')).toBe(false);
    });
});

describe('buildMappingReport', () => {
    const SUPPORTED = ['EUR', 'USD', 'PEN'];

    const mapping: ColumnMapping = {
        transaction_date: 'Date',
        description: 'Note',
        amount: 'Amount',
        currency: 'Currency',
        balance: null,
        creditor_name: null,
        debtor_name: null,
    };

    function row(
        overrides: Partial<Record<string, string | null>> = {},
    ): ParsedRow {
        return {
            Date: '2018-05-14',
            Note: 'Camara',
            Amount: '-396.00',
            Currency: 'PEN',
            ...overrides,
        };
    }

    function report(rows: ParsedRow[], columnMapping: ColumnMapping = mapping) {
        return buildMappingReport(
            rows,
            rows.map((_, index) => index + 2),
            columnMapping,
            DateFormat.YearMonthDay,
            'EUR',
            SUPPORTED,
        );
    }

    it('counts a clean file as all ready, with nothing to report', () => {
        const result = report([row(), row({ Date: '2018-05-18' })]);

        expect(result.total).toBe(2);
        expect(result.readyCount).toBe(2);
        expect(result.skippedCount).toBe(0);
        expect(result.adjustedCount).toBe(0);
        expect(result.problems).toEqual([]);
        expect(result.fields.transaction_date.ok).toBe(2);
        expect(result.fields.description.ok).toBe(2);
        expect(result.fields.amount.ok).toBe(2);
    });

    it('reports a row with no description as skipped, not imported', () => {
        const result = report([row(), row({ Note: '' })]);

        expect(result.readyCount).toBe(1);
        expect(result.skippedCount).toBe(1);
        expect(result.fields.description.skipped).toBe(1);
        expect(result.problems).toHaveLength(1);
        expect(result.problems[0].severity).toBe('skipped');
        expect(result.problems[0].faults).toEqual([
            {
                field: 'description',
                reason: 'No description',
                severity: 'skipped',
            },
        ]);
    });

    it('reports an unreadable amount separately from a missing one', () => {
        const result = report([row({ Amount: 'n/a' }), row({ Amount: '' })]);

        expect(result.fields.amount.skipped).toBe(2);
        expect(result.problems.map((p) => p.faults[0].reason)).toEqual([
            "Amount can't be read",
            'No amount',
        ]);
    });

    it('marks an unsupported currency as adjusted, and still imports the row', () => {
        const result = report([row(), row({ Currency: 'XAU' })]);

        expect(result.readyCount).toBe(2);
        expect(result.skippedCount).toBe(0);
        expect(result.adjustedCount).toBe(1);
        expect(result.fields.currency.adjusted).toBe(1);
        expect(result.problems[0].severity).toBe('adjusted');
        expect(result.problems[0].faults[0].reason).toBe('Will import as EUR');
        expect(result.currencies).toEqual({ used: ['PEN'], fallback: ['XAU'] });
    });

    it('does not count a skipped row as adjusted as well', () => {
        const result = report([row({ Note: '', Currency: 'XAU' })]);

        expect(result.skippedCount).toBe(1);
        expect(result.adjustedCount).toBe(0);
        expect(result.problems[0].severity).toBe('skipped');
        expect(result.problems[0].faults).toHaveLength(2);
    });

    it('keeps both faults on one row instead of listing it twice', () => {
        const result = report([row({ Note: '', Amount: 'n/a' })]);

        expect(result.problems).toHaveLength(1);
        expect(result.problems[0].faults.map((f) => f.field)).toEqual([
            'description',
            'amount',
        ]);
    });

    it('reports the row number from the file, not the index', () => {
        const result = buildMappingReport(
            [row(), row({ Note: '' })],
            [4, 19],
            mapping,
            DateFormat.YearMonthDay,
            'EUR',
            SUPPORTED,
        );

        expect(result.problems[0].rowNumber).toBe(19);
    });

    it('reports the range the chosen date format produced', () => {
        const result = report([
            row({ Date: '2018-05-18' }),
            row({ Date: '2018-05-14' }),
        ]);

        expect(result.dateRange).toEqual({
            from: '2018-05-14',
            to: '2018-05-18',
        });
    });

    it('reports the amount range in cents', () => {
        const result = report([
            row({ Amount: '-396.00' }),
            row({ Amount: '3' }),
        ]);

        expect(result.amountRange).toEqual({ min: -39600, max: 300 });
    });

    it('leaves currency out of it when no column is mapped', () => {
        const result = report([row({ Currency: 'XAU' })], {
            ...mapping,
            currency: null,
        });

        expect(result.adjustedCount).toBe(0);
        expect(result.fields.currency).toEqual({
            ok: 0,
            skipped: 0,
            adjusted: 0,
        });
        expect(result.currencies).toEqual({ used: [], fallback: [] });
        expect(result.problems).toEqual([]);
    });

    it('handles an empty file without inventing ranges', () => {
        const result = report([]);

        expect(result.total).toBe(0);
        expect(result.readyCount).toBe(0);
        expect(result.dateRange).toBeNull();
        expect(result.amountRange).toBeNull();
        expect(result.descriptionSample).toBeNull();
    });
});

describe('formatLocalDate', () => {
    // parseDate builds local midnight, so toISOString() names the day before
    // for anywhere east of Greenwich. CI runs in UTC, where both agree.
    it('names the day the date holds locally, not its UTC day', () => {
        const originalTimezone = process.env.TZ;
        process.env.TZ = 'Europe/Madrid';

        try {
            const date = parseDate('2026-01-31', DateFormat.YearMonthDay)!;

            expect(formatLocalDate(date)).toBe('2026-01-31');
            expect(date.toISOString().slice(0, 10)).toBe('2026-01-30');
        } finally {
            process.env.TZ = originalTimezone;
        }
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

        const { data, rowNumbers } = await parseFile(file);

        // Row 1 is the header, so the first data row is row 2 of the file.
        expect(rowNumbers).toEqual([2, 3]);
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

    it('reads an Apple Numbers file, dropping the empty rows it pads the grid with', async () => {
        const file = new File([numbersFixture()], 'budget.numbers');

        const { headers, data, rowNumbers } = await parseFile(file);

        expect(headers).toEqual([
            'Date',
            'Amount',
            'Currency',
            'Category',
            'Note',
        ]);
        expect(data).toHaveLength(3);
        expect(rowNumbers).toEqual([2, 3, 4]);
        expect(data[0].Amount).toBe(-396);
        expect(data[0].Currency).toBe('PEN');
    });

    it('turns Numbers date cells into ISO date strings the chosen format can read', async () => {
        const file = new File([numbersFixture()], 'budget.numbers');

        const { data } = await parseFile(file);

        // Numbers hands SheetJS a Date, not text or a serial. Left alone it
        // would reach parseDate as "Mon May 14 2018 00:00:00 GMT+0200 (...)".
        expect(data.map((row) => row.Date)).toEqual([
            '2018-05-14',
            '2018-05-15',
            '2018-12-03',
        ]);

        // ISO dates are unambiguous, so the format detection settles on one.
        expect(detectDateFormat(data, 'Date')).toEqual({
            format: DateFormat.YearMonthDay,
            ambiguous: false,
        });
        expect(
            parseDate(data[0].Date as string, DateFormat.YearMonthDay),
        ).toEqual(new Date(2018, 4, 14));
    });

    // CI runs in UTC, where the epoch correction is a no-op and any mistake in
    // it hides. New Zealand is the case that catches it: reading the Date
    // SheetJS builds gives 13 May there, a day before what the file says.
    it('keeps Numbers dates on the right day in a southern-hemisphere timezone', async () => {
        const originalTimezone = process.env.TZ;
        process.env.TZ = 'Pacific/Auckland';

        try {
            const file = new File([numbersFixture()], 'budget.numbers');

            const { data } = await parseFile(file);

            expect(data.map((row) => row.Date)).toEqual([
                '2018-05-14',
                '2018-05-15',
                '2018-12-03',
            ]);
        } finally {
            process.env.TZ = originalTimezone;
        }
    });
});
