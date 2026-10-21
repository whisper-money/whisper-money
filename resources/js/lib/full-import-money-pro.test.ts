import {
    collectCurrencySymbols,
    currencySymbolOf,
    defaultSymbolCurrency,
    moneyProReader,
} from '@/lib/full-import-money-pro';
import {
    buildImport,
    detectAccounts,
    OWN_TRANSFER_KEY,
    type BuildImportInput,
} from '@/lib/full-import-plan';
import {
    defaultMapping,
    detectSource,
    normalizeRows,
} from '@/lib/full-import-profiles';
import { parseImportFile } from '@/lib/transaction-import';
import { type FullImportMapping } from '@/types/full-import';
import { DateFormat } from '@/types/import';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const SUPPORTED = ['EUR', 'USD', 'COP', 'GBP', 'MXN'];

/** A synthetic Money Pro export: made-up accounts, no real person in it. */
function moneyProFile(): File {
    return new File(
        [
            new Uint8Array(
                readFileSync(
                    'resources/js/lib/__fixtures__/money-pro-sample.csv',
                ),
            ),
        ],
        'money-pro.csv',
        { type: 'text/csv' },
    );
}

async function readMoneyPro(
    userCurrency = 'EUR',
    patch: Partial<FullImportMapping> = {},
) {
    const parsed = await parseImportFile(moneyProFile(), 'es-ES');
    const mapping = {
        ...defaultMapping('money_pro', parsed, 'es-ES'),
        ...patch,
    };
    const options = {
        fileName: 'money-pro.csv',
        supportedCurrencies: SUPPORTED,
        userCurrency,
    };
    const normalized = normalizeRows(
        parsed,
        mapping,
        options,
        moneyProReader(parsed.headers),
    );

    return { parsed, mapping, options, normalized };
}

describe('reading a Money Pro export', () => {
    it('is recognised by its header signature and mapped whole', async () => {
        const { parsed, mapping } = await readMoneyPro();

        expect(detectSource(parsed.headers)).toBe('money_pro');
        expect(mapping).toMatchObject({
            date: 'Fecha',
            amount: 'Cantidad',
            account: 'Cuenta',
            description: 'Descripción',
            category: 'Categoría',
            balance: 'Saldo',
            categorySeparator: ':',
            dateFormat: DateFormat.DayMonthYear,
        });
    });

    it('signs expenses and income by their type, with a description fallback', async () => {
        const { normalized } = await readMoneyPro();
        const movements = normalized.rows.filter(
            (row) => row.kind === 'movement',
        );

        expect(normalized.unreadable).toEqual([]);
        expect(movements[0]).toMatchObject({
            date: '2018-02-25',
            amount: 58000,
            description: 'Otros',
            categoryPath: ['Otros'],
            accountName: 'Wallet',
            accountBank: '',
            currency: 'EUR',
            balance: 58000,
        });
        expect(movements[1]).toMatchObject({
            date: '2018-02-26',
            amount: -8400,
            description: 'Toll',
            categoryPath: ['Vehículo', 'Peaje'],
            balance: 49600,
        });
    });

    it('reads a transfer as two movements, each in its own currency', async () => {
        const { normalized } = await readMoneyPro();
        const legs = normalized.rows.filter((row) => row.kind === 'transfer');

        expect(legs).toHaveLength(2);
        expect(legs[0]).toMatchObject({
            accountKey: 'wallet',
            amount: -750,
            currency: 'EUR',
            balance: 48850,
            description: 'Transferencia de dinero',
            notes: 'To Safe box',
            categoryPath: [],
        });
        expect(legs[1]).toMatchObject({
            accountKey: 'safe box',
            accountName: 'Safe box',
            amount: 750,
            currency: 'USD',
            balance: null,
            notes: 'From Wallet',
        });
    });

    it('keeps opening balances and adjustments as balances only', async () => {
        const { normalized } = await readMoneyPro();
        const balances = normalized.rows.filter(
            (row) => row.kind === 'balance',
        );

        expect(balances).toMatchObject([
            { accountName: 'Wallet', amount: 0, balance: 0, currency: 'EUR' },
            {
                accountName: 'Savings',
                amount: 0,
                balance: 8000000,
                date: '2018-01-30',
            },
        ]);
    });

    it('keeps the balance of an adjustment that has no amount', async () => {
        const parsed = await parseImportFile(
            new File(
                [
                    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción\n25/2/18, 4:57;;Wallet;;;120,00 €;;;Ajuste de saldo\n',
                ],
                'adjustment.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            defaultMapping('money_pro', parsed, 'es-ES'),
            { fileName: 'adjustment.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers),
        );

        expect(normalized.unreadable).toEqual([]);
        expect(normalized.rows).toMatchObject([
            { kind: 'balance', balance: 120, currency: 'EUR' },
        ]);
    });

    it('turns down a row whose type it does not know', async () => {
        const parsed = await parseImportFile(
            new File(
                [
                    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción\n25/2/18, 4:57;5,00 €;Wallet;;;5,00 €;;;Reembolso\n',
                ],
                'odd.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            defaultMapping('money_pro', parsed, 'es-ES'),
            { fileName: 'odd.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers),
        );

        expect(normalized.rows).toEqual([]);
        expect(normalized.unreadable).toEqual([
            { rowNumber: 2, reason: 'Unknown type «Reembolso»' },
        ]);
    });
});

describe('currency symbols', () => {
    it('takes the symbol out of an amount cell', () => {
        expect(currencySymbolOf('8.400,00 €')).toBe('€');
        expect(currencySymbolOf('-$1,200.50')).toBe('$');
        expect(currencySymbolOf('1.000,00 COP')).toBe('COP');
        expect(currencySymbolOf('12')).toBe('');
    });

    it('reads $ as the user currency when it is a dollar or a peso, else USD', () => {
        expect(defaultSymbolCurrency('$', SUPPORTED, 'COP')).toBe('COP');
        expect(defaultSymbolCurrency('$', SUPPORTED, 'MXN')).toBe('MXN');
        expect(defaultSymbolCurrency('$', SUPPORTED, 'EUR')).toBe('USD');
        expect(defaultSymbolCurrency('€', SUPPORTED, 'USD')).toBe('EUR');
        expect(defaultSymbolCurrency('£', SUPPORTED, 'EUR')).toBe('GBP');
        expect(defaultSymbolCurrency('cop', SUPPORTED, 'EUR')).toBe('COP');
        expect(defaultSymbolCurrency('₿', SUPPORTED, 'EUR')).toBeNull();
    });

    it('lists the symbols the file uses with what each is read as', async () => {
        const { parsed, mapping, options } = await readMoneyPro('COP');

        expect(
            collectCurrencySymbols(
                parsed.rows,
                parsed.headers,
                mapping,
                options,
            ),
        ).toEqual([
            { symbol: '€', count: 4, code: 'EUR', ambiguous: false },
            { symbol: '$', count: 3, code: 'COP', ambiguous: true },
        ]);
    });

    it('follows the currency the user picked for a symbol', async () => {
        const { normalized } = await readMoneyPro('EUR', {
            currencySymbols: { $: 'COP' },
        });

        expect(
            normalized.rows
                .filter((row) => row.accountName === 'Savings')
                .map((row) => row.currency),
        ).toEqual(['COP', 'COP']);
    });
});

describe('importing a Money Pro export', () => {
    it('sends no transaction for a balance-only row, and transfers as own transfers', async () => {
        const { normalized, mapping } = await readMoneyPro('COP');
        const fileAccounts = detectAccounts(normalized.rows);
        const plan = (name: string, currencyCode: string) => ({
            action: 'create' as const,
            name,
            type: 'checking' as const,
            currencyCode,
            bank: null,
            newBankName: null,
            targetAccountId: null,
            mergeIntoKey: null,
        });
        const input: BuildImportInput = {
            source: 'money_pro',
            fileName: 'money-pro.csv',
            mode: 'add',
            mapping,
            rows: normalized.rows,
            fileAccounts,
            accountPlan: {
                wallet: plan('Wallet', 'EUR'),
                savings: plan('Savings', 'COP'),
                'safe box': plan('Safe box', 'COP'),
            },
            contextAccounts: [],
            nodes: [],
            categoryPlan: {},
            categories: [],
            transfers: {
                own: {
                    categoryId: 'own-category',
                    target: {
                        category_id: 'own-category',
                        name: 'Own transfers',
                        icon: 'Split',
                        color: 'stone',
                    },
                },
                ignored: {
                    categoryId: null,
                    target: {
                        category_id: null,
                        name: 'Ignored',
                        icon: 'Split',
                        color: 'stone',
                    },
                },
            },
        };

        expect(
            Object.fromEntries(
                fileAccounts.map((account) => [account.name, account.count]),
            ),
        ).toEqual({ Wallet: 3, Savings: 1, 'Safe box': 1 });

        const built = buildImport(input);
        const savingsKey = `a${fileAccounts.findIndex((account) => account.name === 'Savings')}`;

        expect(built.transactions).toHaveLength(5);
        expect(
            built.transactions
                .filter((row) => row.category_key === OWN_TRANSFER_KEY)
                .map((row) => row.amount),
        ).toEqual([-75000, 750]);
        expect(
            built.balances.filter((row) => row.account_key === savingsKey),
        ).toEqual([
            { account_key: savingsKey, date: '2018-01-30', balance: 8000000 },
            { account_key: savingsKey, date: '2018-02-02', balance: 6750000 },
        ]);
    });
});
