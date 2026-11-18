import { fixtureGrid } from '@/lib/broker-imports/testing';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    defaultTradeRepublicPlan,
    investedToday,
    tradeRepublicPreviewRows,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import { runTradeRepublicImport } from '@/lib/broker-imports/trade-republic-run';
import { type Account } from '@/types/account';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, create } = vi.hoisted(() => ({
    post: vi.fn(),
    create: vi.fn(),
}));

vi.mock('axios', () => ({ default: { post } }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create },
}));

const TODAY = '2026-03-02';

const tradeRepublicBank = {
    id: 'bank-tr',
    user_id: null,
    name: 'Trade Republic',
    logo: null,
};

const checking: Account = {
    id: 'checking-1',
    name: 'Everyday',
    bank: null,
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

async function sampleImport(
    plan: Partial<TradeRepublicImport['plan']> = {},
): Promise<TradeRepublicImport> {
    const file = parseTradeRepublic(
        await fixtureGrid('trade-republic-sample.csv'),
    );

    return {
        kind: 'trade_republic',
        file,
        today: TODAY,
        plan: { ...defaultTradeRepublicPlan(file, [], null), ...plan },
    };
}

/** The bodies posted to a URL, in order. */
function postedTo(fragment: string): unknown[] {
    return post.mock.calls
        .filter(([url]) => String(url).includes(fragment))
        .map(([, body]) => body);
}

/** Imports the file's newest row into the checking account. */
function importFirstRowIntoChecking(broker: TradeRepublicImport) {
    return runTradeRepublicImport({
        broker,
        rows: tradeRepublicPreviewRows(broker.file, 0, TODAY).slice(0, 1),
        accounts: [checking],
        categories: [],
        banks: [],
        automationRules: [],
    });
}

describe('runTradeRepublicImport', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        let created = 0;
        post.mockImplementation(
            async (url: string, body: { name?: string; type?: string }) => {
                if (url.includes('/balances')) {
                    return { data: { written: 1 } };
                }

                created++;

                return {
                    data: {
                        id: `new-${created}`,
                        name: body.name,
                        type: body.type,
                        currency_code: 'EUR',
                    },
                };
            },
        );
        create.mockImplementation(async (row: { description: string }) => ({
            id: row.description,
        }));
    });

    it('creates both accounts at Trade Republic, imports the rows with their ids, then writes both balance series', async () => {
        const broker = await sampleImport({
            balanceToday: 100_000,
            currentValue: 25_000,
        });
        const rows = tradeRepublicPreviewRows(broker.file, 100_000, TODAY);
        const onAccountCreated = vi.fn();

        const { outcome, balancesFailed } = await runTradeRepublicImport({
            broker,
            rows,
            accounts: [],
            categories: [],
            banks: [tradeRepublicBank],
            automationRules: [],
            onAccountCreated,
        });

        expect(outcome.successCount).toBe(rows.length);
        expect(balancesFailed).toBe(false);

        expect(postedTo('/settings/accounts')).toEqual([
            {
                name: 'Trade Republic',
                type: 'checking',
                currency_code: 'EUR',
                bank_id: 'bank-tr',
            },
            {
                name: 'Trade Republic · Portfolio',
                type: 'investment',
                currency_code: 'EUR',
                bank_id: 'bank-tr',
            },
        ]);
        expect(onAccountCreated.mock.calls.map(([role]) => role)).toEqual([
            'cash',
            'portfolio',
        ]);

        // Every row lands on the cash account, carrying Trade Republic's id.
        expect(create).toHaveBeenCalledTimes(rows.length);
        expect(create.mock.calls[0][0]).toMatchObject({
            account_id: 'new-1',
            source: 'imported',
            external_transaction_id: rows[0].external_id,
        });

        const [cashBalances, portfolioBalances] = post.mock.calls
            .filter(([url]) => String(url).includes('/balances'))
            .map(([url, body]) => ({ url, body }));

        expect(cashBalances.url).toContain('new-1');
        expect(cashBalances.body.balances.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 100_000,
        });
        expect(portfolioBalances.url).toContain('new-2');
        expect(portfolioBalances.body.balances.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 25_000,
            invested_amount: investedToday(broker.file, TODAY),
        });
    });

    it('uses an existing cash account and leaves the portfolio out when told to', async () => {
        const broker = await sampleImport({
            cash: { kind: 'existing', accountId: checking.id },
            portfolio: { kind: 'skip' },
        });
        const rows = tradeRepublicPreviewRows(
            broker.file,
            broker.plan.balanceToday,
            TODAY,
        ).slice(0, 2);

        await runTradeRepublicImport({
            broker,
            rows,
            accounts: [checking],
            categories: [],
            banks: [],
            automationRules: [],
        });

        expect(postedTo('/settings/accounts')).toHaveLength(0);
        expect(create.mock.calls[0][0]).toMatchObject({
            account_id: checking.id,
        });

        const balanceUrls = post.mock.calls
            .map(([url]) => String(url))
            .filter((url) => url.includes('/balances'));

        expect(balanceUrls).toHaveLength(1);
        expect(balanceUrls[0]).toContain(checking.id);
    });

    it('writes no balances when no row made it in', async () => {
        create.mockRejectedValue(new Error('Server said no'));
        const broker = await sampleImport({
            cash: { kind: 'existing', accountId: checking.id },
        });

        const { outcome } = await importFirstRowIntoChecking(broker);

        expect(outcome.successCount).toBe(0);
        expect(postedTo('/balances')).toHaveLength(0);
    });

    it('reports balances that could not be written, the transactions being in', async () => {
        post.mockImplementation(async (url: string) => {
            if (url.includes('/balances')) {
                throw new Error('Server said no');
            }

            return { data: { id: 'new', currency_code: 'EUR' } };
        });
        const broker = await sampleImport({
            cash: { kind: 'existing', accountId: checking.id },
            portfolio: { kind: 'skip' },
        });

        const { outcome, balancesFailed } =
            await importFirstRowIntoChecking(broker);

        expect(outcome.successCount).toBe(1);
        expect(balancesFailed).toBe(true);
    });
});
