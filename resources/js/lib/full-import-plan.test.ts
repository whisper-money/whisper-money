import {
    buildImport,
    categoryNodeId,
    defaultAccountPlan,
    detectAccounts,
    detectCategories,
    guessAccountType,
    IGNORED_KEY,
    isOwnTransferNode,
    latestBalances,
    matchCategory,
    OWN_TRANSFER_KEY,
    resolveAccountPlan,
    resolveCategoryPlan,
    type AccountPlanContext,
    type BuildImportInput,
} from '@/lib/full-import-plan';
import { type Category } from '@/types/category';
import {
    type ContextAccount,
    type FullImportMapping,
    type NormalizedRow,
    type TransferTarget,
} from '@/types/full-import';
import { DateFormat } from '@/types/import';
import { describe, expect, it } from 'vitest';

function row(overrides: Partial<NormalizedRow>): NormalizedRow {
    return {
        rowNumber: 2,
        date: '2026-09-01',
        amount: -10,
        description: 'Movement',
        notes: null,
        accountKey: 'wise',
        accountName: 'Wise',
        categoryPath: [],
        currency: 'EUR',
        balance: null,
        iban: null,
        externalId: null,
        ignored: false,
        ...overrides,
    };
}

function category(
    id: string,
    name: string,
    overrides: Partial<Category> = {},
): Category {
    return {
        id,
        name,
        icon: 'Wallet',
        color: 'blue',
        type: 'expense',
        cashflow_direction: 'hidden',
        parent_id: null,
        ...overrides,
    };
}

function account(overrides: Partial<ContextAccount>): ContextAccount {
    return {
        id: 'acc-1',
        name: 'Account',
        type: 'checking',
        currency_code: 'EUR',
        connected: false,
        archived: false,
        transactions_count: 0,
        bank: null,
        ...overrides,
    };
}

const defaultNames = [
    { en: 'Insurance', es: 'Seguros' },
    { en: 'Groceries', es: 'Supermercado' },
];

describe('matchCategory', () => {
    const categories = [
        category('food', 'Alimentación'),
        category('groceries', 'Supermercado', { parent_id: 'food' }),
        category('insurance', 'Insurance'),
        category('home', 'Artículos del hogar'),
        category('fees', 'Servicios financieros y comisiones'),
    ];

    it('matches the same name, case and accents aside', () => {
        expect(
            matchCategory('alimentacion', categories, defaultNames),
        ).toMatchObject({ category: { id: 'food' }, kind: 'exact' });
    });

    it('matches a seeded category through its other language', () => {
        expect(
            matchCategory('Seguros', categories, defaultNames),
        ).toMatchObject({ category: { id: 'insurance' }, kind: 'exact' });
    });

    it('matches a plural to its singular', () => {
        expect(
            matchCategory('Supermercados', categories, defaultNames),
        ).toMatchObject({ category: { id: 'groceries' }, kind: 'exact' });
    });

    it('flags a category whose words contain the other as similar', () => {
        expect(matchCategory('Hogar', categories, defaultNames)).toMatchObject({
            category: { id: 'home' },
            kind: 'similar',
        });
        expect(
            matchCategory('Comisiones', categories, defaultNames),
        ).toMatchObject({ category: { id: 'fees' }, kind: 'similar' });
    });

    it('finds nothing for an unrelated name', () => {
        expect(matchCategory('Empresa', categories, defaultNames)).toBeNull();
    });
});

describe('detectCategories and resolveCategoryPlan', () => {
    const rows = [
        row({ categoryPath: ['Empresa', 'Gastos Empresa'], amount: -20 }),
        row({ categoryPath: ['Empresa', 'Gastos Empresa'], amount: -5 }),
        row({ categoryPath: ['Alimentación', 'Restaurantes'], amount: -12 }),
        row({ categoryPath: ['Ingresos alquiler'], amount: 900 }),
        row({ categoryPath: ['Traspasos Propios'], amount: 50 }),
    ];
    const categories = [category('food', 'Alimentación')];
    const nodes = detectCategories(rows);
    const plan = resolveCategoryPlan(nodes, {}, { categories, defaultNames });

    it('builds the tree with parents before their children', () => {
        expect(nodes.map((node) => node.id)).toEqual([
            'empresa',
            'empresa>gastos empresa',
            'alimentacion',
            'alimentacion>restaurantes',
            'ingresos alquiler',
            'traspasos propios',
        ]);
        expect(nodes.find((node) => node.id === 'empresa')?.count).toBe(0);
    });

    it('creates unknown categories with the type their money suggests', () => {
        expect(plan['empresa']).toMatchObject({
            action: 'create',
            type: 'expense',
        });
        expect(plan['empresa>gastos empresa']).toMatchObject({
            action: 'create',
            type: 'expense',
        });
        expect(plan['ingresos alquiler']).toMatchObject({
            action: 'create',
            type: 'income',
        });
    });

    it('matches a parent and creates its unknown child under it', () => {
        expect(plan['alimentacion']).toMatchObject({
            action: 'match',
            categoryId: 'food',
        });
        expect(plan['alimentacion>restaurantes']).toMatchObject({
            action: 'create',
            type: 'expense',
        });
    });

    it('gives a new child its parent type even when the user changed it', () => {
        const changed = resolveCategoryPlan(
            nodes,
            {
                empresa: { type: 'income' },
                'empresa>gastos empresa': { type: 'expense' },
            },
            { categories, defaultNames },
        );

        expect(changed['empresa>gastos empresa'].type).toBe('income');
    });

    it('recognises own transfers', () => {
        const own = nodes.find((node) => node.id === 'traspasos propios');

        expect(own && isOwnTransferNode(own, nodes)).toBe(true);
        expect(isOwnTransferNode(nodes[0], nodes)).toBe(false);
    });
});

describe('latestBalances', () => {
    it('takes the first row of a day in a newest-first export', () => {
        const balances = latestBalances([
            row({ date: '2026-09-22', balance: 100 }),
            row({ date: '2026-09-22', balance: 90 }),
            row({ date: '2026-09-20', balance: 50 }),
        ]);

        expect(Object.fromEntries(balances)).toEqual({
            '2026-09-22': 100,
            '2026-09-20': 50,
        });
    });

    it('takes the last row of a day in a date-ordered export', () => {
        const balances = latestBalances([
            row({ date: '2026-09-20', balance: 50 }),
            row({ date: '2026-09-22', balance: 90 }),
            row({ date: '2026-09-22', balance: 100 }),
            row({ date: '2026-09-23', balance: null }),
        ]);

        expect(Object.fromEntries(balances)).toEqual({
            '2026-09-20': 50,
            '2026-09-22': 100,
        });
    });
});

describe('accounts', () => {
    const context: AccountPlanContext = {
        mode: 'add',
        accounts: [
            account({ id: 'manual', name: 'BBVA Conjunta' }),
            account({ id: 'connected', name: 'Revolut', connected: true }),
            account({ id: 'loan', name: 'Hipoteca', type: 'loan' }),
        ],
        mappableAccountIds: ['manual'],
        userCurrency: 'EUR',
        supportedCurrencies: ['EUR', 'USD'],
        sourceLabel: 'Banktrack',
        banks: { Wise: { id: 'bank-wise', name: 'Wise', logo: null } },
    };
    const fileAccounts = detectAccounts([
        row({ accountKey: 'bbva conjunta', accountName: 'BBVA Conjunta' }),
        row({ accountKey: 'revolut', accountName: 'Revolut' }),
        row({ accountKey: 'revolut', accountName: 'Revolut' }),
        row({ accountKey: 'wise', accountName: 'Wise', currency: 'USD' }),
        row({ accountKey: 'hipoteca', accountName: 'Hipoteca' }),
        row({ accountKey: 'cash', accountName: 'Cash' }),
    ]);
    const byKey = (key: string) => fileAccounts.find((one) => one.key === key)!;

    it('maps onto a manual account of the same name', () => {
        expect(
            defaultAccountPlan(byKey('bbva conjunta'), context),
        ).toMatchObject({
            action: 'map',
            targetAccountId: 'manual',
        });
    });

    it('never targets a connected account: a new one named after the source instead', () => {
        expect(defaultAccountPlan(byKey('revolut'), context)).toMatchObject({
            action: 'create',
            name: 'Revolut (Banktrack)',
            targetAccountId: null,
        });
    });

    it('goes back into the separate account an earlier import made for a connected namesake', () => {
        const again: AccountPlanContext = {
            ...context,
            accounts: [
                ...context.accounts,
                account({ id: 'separate', name: 'Revolut (Banktrack)' }),
            ],
            mappableAccountIds: ['manual', 'separate'],
        };

        expect(defaultAccountPlan(byKey('revolut'), again)).toMatchObject({
            action: 'map',
            targetAccountId: 'separate',
        });
    });

    it('never targets an account that keeps no ledger', () => {
        expect(defaultAccountPlan(byKey('hipoteca'), context).action).toBe(
            'create',
        );
    });

    it('takes the bank, currency and a type guess for a new account', () => {
        expect(defaultAccountPlan(byKey('wise'), context)).toMatchObject({
            action: 'create',
            currencyCode: 'USD',
            bank: { id: 'bank-wise' },
            type: 'checking',
        });
        expect(defaultAccountPlan(byKey('cash'), context)).toMatchObject({
            type: 'others',
            bank: null,
        });
        expect(guessAccountType('Wise Ahorro')).toBe('savings');
        expect(guessAccountType('Tarjeta Amex')).toBe('credit_card');
    });

    it('turns a mapping it cannot honour back into a new account', () => {
        const plan = resolveAccountPlan(
            fileAccounts,
            {
                revolut: {
                    ...defaultAccountPlan(byKey('revolut'), context),
                    action: 'map',
                    targetAccountId: 'connected',
                },
                cash: {
                    ...defaultAccountPlan(byKey('cash'), context),
                    action: 'merge',
                    mergeIntoKey: 'revolut',
                },
            },
            context,
        );

        expect(plan['revolut'].action).toBe('create');
        expect(plan['cash'].action).toBe('merge');
        expect(
            resolveAccountPlan(fileAccounts, {}, { ...context, mode: 'wipe' })[
                'bbva conjunta'
            ].action,
        ).toBe('create');
    });
});

describe('buildImport', () => {
    const mapping: FullImportMapping = {
        date: 'Fecha',
        amount: 'Importe',
        description: 'Concepto',
        account: 'Banco',
        accountDetail: null,
        splitByAccountDetail: false,
        notes: null,
        category: 'Categorías',
        categorySeparator: ',',
        currency: 'Moneda',
        balance: 'Balance',
        iban: null,
        externalId: 'ID',
        ignored: 'Ignorada',
        dateFormat: DateFormat.DayMonthYear,
    };
    const target = (name: string): TransferTarget => ({
        category_id: null,
        name,
        icon: 'Split',
        color: 'stone',
    });
    const rows = [
        row({
            accountKey: 'wise',
            date: '2026-09-02',
            amount: -158.68,
            balance: 1000,
            categoryPath: ['Empresa', 'Gastos Empresa'],
            externalId: 'bt-1',
        }),
        row({
            accountKey: 'wise',
            date: '2026-09-01',
            amount: 10.04,
            categoryPath: ['Traspasos Propios'],
            externalId: 'bt-2',
        }),
        row({
            accountKey: 'cash',
            accountName: 'Cash',
            date: '2026-09-01',
            amount: -0.9,
            balance: 30,
            categoryPath: ['Otros'],
            ignored: true,
        }),
        row({ accountKey: 'old', accountName: 'Old', amount: -1 }),
    ];
    const fileAccounts = detectAccounts(rows);
    const nodes = detectCategories(
        rows.filter((one) => !one.ignored && one.accountKey !== 'old'),
    );

    function input(
        overrides: Partial<BuildImportInput> = {},
    ): BuildImportInput {
        return {
            source: 'banktrack',
            fileName: 'export.csv',
            mode: 'add',
            mapping,
            rows,
            fileAccounts,
            accountPlan: {
                wise: {
                    action: 'create',
                    name: 'Wise',
                    type: 'checking',
                    currencyCode: 'EUR',
                    bank: null,
                    targetAccountId: null,
                    mergeIntoKey: null,
                },
                cash: {
                    action: 'merge',
                    name: 'Cash',
                    type: 'others',
                    currencyCode: 'EUR',
                    bank: null,
                    targetAccountId: null,
                    mergeIntoKey: 'wise',
                },
                old: {
                    action: 'skip',
                    name: 'Old',
                    type: 'checking',
                    currencyCode: 'EUR',
                    bank: null,
                    targetAccountId: null,
                    mergeIntoKey: null,
                },
            },
            contextAccounts: [],
            nodes,
            categoryPlan: resolveCategoryPlan(
                nodes,
                {},
                {
                    categories: [],
                    defaultNames,
                },
            ),
            categories: [],
            transfers: {
                own: { categoryId: 'own-id', target: target('Own account') },
                ignored: {
                    categoryId: null,
                    target: target('Other transfers'),
                },
            },
            ...overrides,
        };
    }

    it('sends the rows of imported accounts only, in minor units', () => {
        const built = buildImport(input());

        expect(built.transactions.map((one) => one.amount)).toEqual([
            -15868, 1004, -90,
        ]);
        expect(built.payload.expected_transactions).toBe(3);
        expect(built.payload.accounts).toEqual([
            expect.objectContaining({ key: 'a0', action: 'create' }),
            { key: 'a1', action: 'merge', merge_into_key: 'a0' },
            { key: 'a2', action: 'skip' },
        ]);
    });

    it('files own transfers and ignored rows under their transfer keys', () => {
        const built = buildImport(input());
        const keys = built.transactions.map((one) => one.category_key);

        expect(keys[1]).toBe(OWN_TRANSFER_KEY);
        expect(keys[2]).toBe(IGNORED_KEY);
        expect(built.payload.categories).toContainEqual({
            key: OWN_TRANSFER_KEY,
            action: 'match',
            category_id: 'own-id',
        });
        expect(built.payload.categories).toContainEqual(
            expect.objectContaining({
                key: IGNORED_KEY,
                action: 'create',
                name: 'Other transfers',
                type: 'transfer',
            }),
        );
    });

    it('creates the hierarchy parents first', () => {
        const created = buildImport(input()).payload.categories.filter(
            (entry) =>
                entry.key !== OWN_TRANSFER_KEY && entry.key !== IGNORED_KEY,
        );
        const parent = created.find((entry) => entry.name === 'Empresa');
        const child = created.find((entry) => entry.name === 'Gastos Empresa');

        expect(parent).toMatchObject({ action: 'create', parent_key: null });
        expect(child).toMatchObject({
            action: 'create',
            parent_key: parent?.key,
            color: parent?.color,
        });
        expect(categoryNodeId(['Empresa', 'Gastos Empresa'])).toBe(
            'empresa>gastos empresa',
        );
    });

    it("only sends the balances of accounts that own them, the day's last one", () => {
        const built = buildImport(input());

        expect(built.balances).toEqual([
            { account_key: 'a0', date: '2026-09-02', balance: 100000 },
        ]);
        expect(
            buildImport(input({ mapping: { ...mapping, balance: null } }))
                .balances,
        ).toEqual([]);
    });

    it('asks for the wipe to be confirmed in wipe mode only', () => {
        expect(buildImport(input()).payload.confirm_wipe).toBeUndefined();
        expect(buildImport(input({ mode: 'wipe' })).payload.confirm_wipe).toBe(
            true,
        );
    });
});
