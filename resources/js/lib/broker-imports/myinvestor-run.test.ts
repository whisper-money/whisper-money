import { parseMyInvestorCash } from '@/lib/broker-imports/myinvestor-cash';
import {
    defaultMyInvestorCashPlan,
    defaultMyInvestorSecuritiesPlan,
    myInvestorCashPreviewRows,
    type MyInvestorCashImport,
    type MyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-import';
import {
    runMyInvestorCashImport,
    runMyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-run';
import { parseMyInvestorSecurities } from '@/lib/broker-imports/myinvestor-securities';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, create } = vi.hoisted(() => ({
    post: vi.fn(),
    create: vi.fn(),
}));

vi.mock('axios', () => ({ default: { post } }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create },
}));

const TODAY = '2026-10-12';

const myInvestorBank = {
    id: 'bank-mi',
    user_id: null,
    name: 'MyInvestor',
    logo: null,
};

const RUN_OPTIONS = {
    accounts: [],
    categories: [],
    banks: [myInvestorBank],
    automationRules: [],
};

/** The bodies posted to a URL, in order. */
function postedTo(fragment: string): unknown[] {
    return post.mock.calls
        .filter(([url]) => String(url).includes(fragment))
        .map(([, body]) => body);
}

async function cashImport(): Promise<MyInvestorCashImport> {
    const file = parseMyInvestorCash(await fixtureGrid('myinvestor-cash.xlsx'));

    return {
        kind: 'myinvestor_cash',
        file,
        today: TODAY,
        plan: defaultMyInvestorCashPlan(file, [], null),
    };
}

async function securitiesImport(
    currentValue: number | null,
): Promise<MyInvestorSecuritiesImport> {
    return {
        kind: 'myinvestor_securities',
        file: parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xls'),
        ),
        today: TODAY,
        plan: { ...defaultMyInvestorSecuritiesPlan([]), currentValue },
    };
}

describe('MyInvestor runs', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        post.mockImplementation(
            async (url: string, body: { name?: string; type?: string }) =>
                url.includes('/balances')
                    ? { data: { written: 1 } }
                    : {
                          data: {
                              id: 'new-1',
                              name: body.name,
                              type: body.type,
                              currency_code: 'EUR',
                          },
                      },
        );
        create.mockImplementation(async (row: { description: string }) => ({
            id: row.description,
        }));
    });

    it('creates the MyInvestor savings account, imports the movements with their ids, then its daily balances', async () => {
        const broker = await cashImport();
        const onAccountCreated = vi.fn();

        const { outcome, balancesFailed } = await runMyInvestorCashImport(
            broker,
            {
                ...RUN_OPTIONS,
                rows: myInvestorCashPreviewRows(broker.file, 123456, TODAY),
                onAccountCreated,
            },
        );

        expect(postedTo('/settings/accounts')).toEqual([
            {
                name: 'MyInvestor',
                type: 'savings',
                currency_code: 'EUR',
                bank_id: 'bank-mi',
            },
        ]);
        expect(onAccountCreated).toHaveBeenCalledWith(
            'cash',
            expect.objectContaining({ id: 'new-1' }),
        );
        expect(outcome.successCount).toBe(5);
        expect(
            create.mock.calls.every(
                ([row]) =>
                    row.account_id === 'new-1' &&
                    row.external_transaction_id.startsWith('myinvestor-'),
            ),
        ).toBe(true);
        expect(balancesFailed).toBe(false);

        const [balances] = postedTo('/balances') as {
            balances: { balance_date: string; balance: number }[];
        }[];

        expect(balances.balances.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 123456,
        });
    });

    it('writes only the portfolio balances for a securities file, no transactions', async () => {
        const broker = await securitiesImport(160000);
        const onAccountCreated = vi.fn();

        const { outcome, balancesFailed } = await runMyInvestorSecuritiesImport(
            broker,
            {
                ...RUN_OPTIONS,
                rows: [],
                onAccountCreated,
            },
        );

        expect(create).not.toHaveBeenCalled();
        expect(outcome.successCount).toBe(0);
        expect(balancesFailed).toBe(false);
        expect(postedTo('/settings/accounts')).toEqual([
            {
                name: 'MyInvestor · Portfolio',
                type: 'investment',
                currency_code: 'EUR',
                bank_id: 'bank-mi',
            },
        ]);
        expect(onAccountCreated).toHaveBeenCalledWith(
            'portfolio',
            expect.objectContaining({ id: 'new-1' }),
        );

        const [balances] = postedTo('/balances') as {
            balances: {
                balance_date: string;
                balance: number;
                invested_amount: number;
            }[];
        }[];

        expect(balances.balances.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 160000,
            invested_amount: 151000,
        });
    });

    it('tells when the portfolio balances could not be written', async () => {
        const broker = await securitiesImport(null);
        const existing = {
            id: 'mi-portfolio',
            name: 'MyInvestor · Portfolio',
            bank: null,
            type: 'investment' as const,
            currency_code: 'EUR',
            banking_connection_id: null,
            external_account_id: null,
            linked_at: null,
        };
        post.mockRejectedValue(new Error('Server said no'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const { balancesFailed } = await runMyInvestorSecuritiesImport(
            {
                ...broker,
                plan: {
                    ...broker.plan,
                    portfolio: { kind: 'existing', accountId: existing.id },
                },
            },
            { ...RUN_OPTIONS, accounts: [existing], rows: [] },
        );

        expect(balancesFailed).toBe(true);
        expect(postedTo('/settings/accounts')).toEqual([]);
    });
});
