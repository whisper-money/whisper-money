import { fixtureGrid } from '@/lib/broker-imports/testing';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    cashBalancePayload,
    defaultTradeRepublicPlan,
    investedToday,
    isTradeRepublicBank,
    portfolioBalancePayload,
    tradeRepublicPreviewRows,
    tradeRepublicSummary,
} from '@/lib/broker-imports/trade-republic-import';
import { type Account } from '@/types/account';
import { describe, expect, it } from 'vitest';

const TODAY = '2026-03-02';

async function readSample() {
    return parseTradeRepublic(await fixtureGrid('trade-republic-sample.csv'));
}

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

const tradeRepublic = {
    id: 'bank-tr',
    user_id: null,
    name: 'Trade Republic',
    logo: null,
};

describe('tradeRepublicSummary', () => {
    it('counts each row once, cash and trades alike, with its date range', async () => {
        const summary = tradeRepublicSummary(await readSample());

        // 22 cash movements; every trade is one of them.
        expect(summary).toEqual({
            movements: 22,
            from: '2026-01-02',
            to: '2026-02-28',
        });
    });
});

describe('isTradeRepublicBank', () => {
    it('reads the bank by its name, whatever its spacing or case', () => {
        expect(isTradeRepublicBank(tradeRepublic)).toBe(true);
        expect(isTradeRepublicBank({ name: 'traderepublic' })).toBe(true);
        expect(isTradeRepublicBank({ name: 'BBVA' })).toBe(false);
        expect(isTradeRepublicBank(null)).toBe(false);
    });
});

describe('defaultTradeRepublicPlan', () => {
    it('creates both accounts when there is nothing to reuse', async () => {
        const file = await readSample();

        expect(defaultTradeRepublicPlan(file, [], null)).toEqual({
            cash: { kind: 'create' },
            balanceToday: file.cash.reduce((sum, one) => sum + one.amount, 0),
            portfolio: { kind: 'create' },
            currentValue: null,
        });
    });

    it('starts on the account picked on the first step', async () => {
        const picked = anAccount({ id: 'picked' });
        const plan = defaultTradeRepublicPlan(
            await readSample(),
            [picked],
            'picked',
        );

        expect(plan.cash).toEqual({ kind: 'existing', accountId: 'picked' });
    });

    it('reuses the accounts already kept at Trade Republic', async () => {
        const plan = defaultTradeRepublicPlan(
            await readSample(),
            [
                anAccount({ id: 'other' }),
                anAccount({ id: 'tr-cash', bank: tradeRepublic }),
                anAccount({
                    id: 'tr-portfolio',
                    type: 'investment',
                    bank: tradeRepublic,
                }),
            ],
            null,
        );

        expect(plan.cash).toEqual({ kind: 'existing', accountId: 'tr-cash' });
        expect(plan.portfolio).toEqual({
            kind: 'existing',
            accountId: 'tr-portfolio',
        });
    });
});

describe('defaultTradeRepublicPlan on a re-import', () => {
    it('reuses the accounts a past import created, even with no bank attached', async () => {
        const plan = defaultTradeRepublicPlan(
            await readSample(),
            [
                anAccount({ id: 'tr-cash', name: 'Trade Republic' }),
                anAccount({
                    id: 'tr-portfolio',
                    name: 'Trade Republic · Portfolio',
                    type: 'investment',
                }),
            ],
            null,
        );

        expect(plan.cash).toEqual({ kind: 'existing', accountId: 'tr-cash' });
        expect(plan.portfolio).toEqual({
            kind: 'existing',
            accountId: 'tr-portfolio',
        });
    });
});

describe('tradeRepublicPreviewRows', () => {
    it('turns every cash movement into a row with its id and its day-end balance, newest first', async () => {
        const file = await readSample();
        const rows = tradeRepublicPreviewRows(file, 100_000, TODAY);

        expect(rows).toHaveLength(file.cash.length);
        expect(rows[0].transaction_date).toBe('2026-02-28');
        expect(rows.at(-1)?.transaction_date).toBe('2026-01-02');
        expect(rows.every((row) => row.external_id)).toBe(true);
        // Nothing moved after the last row, so its day ends on today's balance.
        expect(rows[0].balance).toBe(100_000);
        expect(rows.find((row) => row.description === 'Buy · Apple')).toEqual(
            expect.objectContaining({ amount: -669, currency_code: 'EUR' }),
        );
    });
});

describe('cashBalancePayload', () => {
    it('writes one balance a day from the first movement to today, ending on today’s', async () => {
        const payload = cashBalancePayload(await readSample(), 100_000, TODAY);

        expect(payload[0].balance_date).toBe('2026-01-02');
        expect(payload.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 100_000,
        });
        // 2 Jan to 2 Mar, both included.
        expect(payload).toHaveLength(60);
    });
});

describe('portfolioBalancePayload', () => {
    it('values past days at what was invested and today at the value typed', async () => {
        const file = await readSample();
        const invested = investedToday(file, TODAY);
        const payload = portfolioBalancePayload(file, 25_000, TODAY);

        expect(payload[0].balance_date).toBe('2026-01-15');
        expect(payload[0].balance).toBe(payload[0].invested_amount);
        expect(payload.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 25_000,
            invested_amount: invested,
        });
    });

    it('values today at what was invested when no value was typed', async () => {
        const file = await readSample();

        expect(portfolioBalancePayload(file, null, TODAY).at(-1)).toEqual({
            balance_date: TODAY,
            balance: investedToday(file, TODAY),
            invested_amount: investedToday(file, TODAY),
        });
    });
});
