import { readBrokerImport } from '@/lib/broker-imports/broker-import';
import {
    brokerLinkAfterImport,
    brokerMemoryInUse,
    type BrokerLink,
} from '@/lib/broker-imports/broker-memory';
import { fixtureFile } from '@/lib/broker-imports/testing';
import { investedToday } from '@/lib/broker-imports/trade-republic-import';
import { type Account } from '@/types/account';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/import-config-storage', () => ({
    saveBrokerLink: vi.fn(),
}));

const TODAY = '2026-03-02';

function anAccount(overrides: Partial<Account>): Account {
    return {
        id: 'account',
        name: 'Account',
        bank: null,
        type: 'checking',
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
        ...overrides,
    };
}

// Neither is at Trade Republic by bank or by the name the import gives: the
// heuristic finds neither, only a remembered import can.
const renamedCash = anAccount({ id: 'cash', name: 'Spending' });
const renamedPortfolio = anAccount({
    id: 'portfolio',
    name: 'My ETFs',
    type: 'investment',
});
const tradeRepublicCash = anAccount({
    id: 'tr-cash',
    name: 'Trade Republic',
});

function trLink(overrides: Partial<BrokerLink['config']> = {}): BrokerLink {
    return {
        accountId: renamedCash.id,
        config: {
            broker: 'trade_republic',
            portfolioAccountId: renamedPortfolio.id,
            lastImportedAt: '2026-02-01T10:00:00.000Z',
            lastValueMinor: 130_000,
            lastValueDate: '2026-02-01',
            lastInvestedMinor: 100_000,
            ...overrides,
        },
    };
}

function readTradeRepublic(
    accounts: Account[],
    links: BrokerLink[],
    selectedAccountId: string | null = null,
) {
    return readBrokerImport(
        fixtureFile('trade-republic-sample.csv'),
        accounts,
        selectedAccountId,
        async () => links,
        TODAY,
    );
}

describe('readBrokerImport with what was remembered', () => {
    it('looks exactly as a first import when nothing was remembered', async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio],
            [],
        );

        expect(broker?.memory).toBeUndefined();
        expect(broker?.kind === 'trade_republic' && broker.plan).toMatchObject({
            cash: { kind: 'create' },
            portfolio: { kind: 'create' },
        });
    });

    it('picks the accounts of last time even when the heuristic misses them', async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio],
            [trLink()],
        );

        expect(broker?.kind === 'trade_republic' && broker.plan).toMatchObject({
            cash: { kind: 'existing', accountId: renamedCash.id },
            portfolio: { kind: 'existing', accountId: renamedPortfolio.id },
        });
        expect(broker && brokerMemoryInUse(broker)?.config.lastValueMinor).toBe(
            130_000,
        );
    });

    it('falls back to the heuristic for a portfolio that is gone or archived', async () => {
        for (const accounts of [
            [renamedCash],
            [renamedCash, { ...renamedPortfolio, archived_at: '2026-02-10' }],
        ]) {
            const broker = await readTradeRepublic(accounts, [trLink()]);

            expect(
                broker?.kind === 'trade_republic' && broker.plan,
            ).toMatchObject({
                cash: { kind: 'existing', accountId: renamedCash.id },
                portfolio: { kind: 'create' },
            });
        }
    });

    it('ignores a link kept on an account that is gone', async () => {
        const broker = await readTradeRepublic([renamedPortfolio], [trLink()]);

        expect(broker?.memory).toBeUndefined();
        expect(broker?.kind === 'trade_republic' && broker.plan).toMatchObject({
            cash: { kind: 'create' },
            portfolio: { kind: 'create' },
        });
    });

    it("ignores another broker's link", async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio],
            [
                {
                    ...trLink(),
                    config: { ...trLink().config, broker: 'myinvestor_cash' },
                },
            ],
        );

        expect(broker?.memory).toBeUndefined();
    });

    it('only goes by the link on the account picked on the first step', async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio, tradeRepublicCash],
            [trLink()],
            tradeRepublicCash.id,
        );

        expect(broker?.memory).toBeUndefined();
        expect(broker?.kind === 'trade_republic' && broker.plan.cash).toEqual({
            kind: 'existing',
            accountId: tradeRepublicCash.id,
        });
    });

    it("prefers the link on the account the heuristic found over a later one's", async () => {
        const onHeuristic = {
            ...trLink({ lastImportedAt: '2026-01-01T10:00:00.000Z' }),
            accountId: tradeRepublicCash.id,
        };
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio, tradeRepublicCash],
            [trLink(), onHeuristic],
        );

        expect(broker?.memory?.accountId).toBe(tradeRepublicCash.id);
    });

    it('remembers the MyInvestor portfolio on the portfolio account itself', async () => {
        const broker = await readBrokerImport(
            fixtureFile('myinvestor-salida.xls'),
            [renamedPortfolio],
            null,
            async () => [
                {
                    accountId: renamedPortfolio.id,
                    config: {
                        broker: 'myinvestor_securities',
                        lastImportedAt: '2026-02-01T10:00:00.000Z',
                    },
                },
            ],
            TODAY,
        );

        expect(
            broker?.kind === 'myinvestor_securities' && broker.plan.portfolio,
        ).toEqual({ kind: 'existing', accountId: renamedPortfolio.id });
    });

    it('does not ask for what was remembered for a file of no broker', async () => {
        const loadLinks = vi.fn(async () => []);
        const broker = await readBrokerImport(
            new File(['Date,Amount\n2026-01-01,10\n'], 'bank.csv'),
            [],
            null,
            loadLinks,
            TODAY,
        );

        expect(broker).toBeNull();
        expect(loadLinks).not.toHaveBeenCalled();
    });

    it('stops going by last time once another account is picked', async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio, tradeRepublicCash],
            [trLink()],
        );

        expect(
            broker &&
                brokerMemoryInUse({
                    ...broker,
                    plan: {
                        ...broker.plan,
                        cash: {
                            kind: 'existing',
                            accountId: tradeRepublicCash.id,
                        },
                    },
                } as typeof broker),
        ).toBeNull();
    });
});

describe('brokerLinkAfterImport', () => {
    const now = new Date('2026-03-02T09:30:00.000Z');

    it('keeps the accounts, the value typed and what was invested on the cash account', async () => {
        const broker = await readTradeRepublic([], []);

        if (broker?.kind !== 'trade_republic') {
            throw new Error('Not a Trade Republic import');
        }

        const link = brokerLinkAfterImport(
            { ...broker, plan: { ...broker.plan, currentValue: 150_000 } },
            { cash: renamedCash, portfolio: renamedPortfolio },
            now,
        );

        expect(link).toEqual({
            accountId: renamedCash.id,
            config: {
                broker: 'trade_republic',
                portfolioAccountId: renamedPortfolio.id,
                lastImportedAt: '2026-03-02T09:30:00.000Z',
                lastInvestedMinor: investedToday(broker.file, TODAY),
                lastValueMinor: 150_000,
                lastValueDate: TODAY,
            },
        });
    });

    it('keeps the value typed before when none was typed this time', async () => {
        const broker = await readTradeRepublic(
            [renamedCash, renamedPortfolio],
            [trLink()],
        );

        const link =
            broker &&
            brokerLinkAfterImport(
                broker,
                { cash: renamedCash, portfolio: renamedPortfolio },
                now,
            );

        expect(link?.config).toMatchObject({
            lastValueMinor: 130_000,
            lastValueDate: '2026-02-01',
        });
    });

    it('remembers a skipped portfolio as none', async () => {
        const broker = await readTradeRepublic([], []);

        if (broker?.kind !== 'trade_republic') {
            throw new Error('Not a Trade Republic import');
        }

        const link = brokerLinkAfterImport(
            {
                ...broker,
                plan: { ...broker.plan, portfolio: { kind: 'skip' } },
            },
            { cash: renamedCash },
            now,
        );

        expect(link?.config).toMatchObject({
            portfolioAccountId: null,
            lastInvestedMinor: null,
        });
    });
});
