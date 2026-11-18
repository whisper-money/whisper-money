import { parseMyInvestorCash } from '@/lib/broker-imports/myinvestor-cash';
import {
    defaultMyInvestorCashPlan,
    defaultMyInvestorSecuritiesPlan,
    fileBalanceToday,
    isMyInvestorAccount,
    myInvestorCashBalancePayload,
    myInvestorCashPreviewRows,
    myInvestorCashSummary,
    myInvestorInvestedToday,
    myInvestorPortfolioPayload,
    myInvestorSecuritiesSummary,
} from '@/lib/broker-imports/myinvestor-import';
import { parseMyInvestorSecurities } from '@/lib/broker-imports/myinvestor-securities';
import { endOfDayBalances } from '@/lib/broker-imports/series';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { type CashMovement } from '@/lib/broker-imports/types';
import { type Account } from '@/types/account';
import { describe, expect, it } from 'vitest';

const TODAY = '2026-10-12';

function account(overrides: Partial<Account>): Account {
    return {
        id: 'account-1',
        name: 'Everyday',
        bank: null,
        type: 'checking',
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
        ...overrides,
    };
}

const myInvestorBank = {
    id: 'bank-mi',
    user_id: null,
    name: 'MyInvestor',
    logo: null,
};

async function cashFile(name = 'myinvestor-cash.xlsx') {
    return parseMyInvestorCash(await fixtureGrid(name));
}

async function securitiesFile() {
    return parseMyInvestorSecurities(
        await fixtureGrid('myinvestor-salida.xls'),
    );
}

describe('MyInvestor current-account import', () => {
    it("takes today's balance from the file's last Saldo", async () => {
        const file = await cashFile();

        expect(fileBalanceToday(file)).toBe(123456);
        expect(defaultMyInvestorCashPlan(file, [], null)).toEqual({
            cash: { kind: 'create' },
            balanceToday: 123456,
        });
    });

    it('adds the movements up when the file has no balance column', async () => {
        const file = await cashFile('myinvestor-cash.csv');

        expect(fileBalanceToday(file)).toBeNull();
        expect(defaultMyInvestorCashPlan(file, [], null).balanceToday).toBe(
            file.movements.reduce((sum, one) => sum + one.amount, 0),
        );
    });

    it("leaves the balance to be worked out when the file's last day has none", async () => {
        const file = await cashFile();
        const [last, ...earlier] = [...file.movements].reverse();
        const withoutBalance = { ...last, balance: undefined };

        expect(
            fileBalanceToday({
                ...file,
                movements: [...earlier.reverse(), withoutBalance],
            }),
        ).toBeNull();
    });

    it('summarises the movements and their range', async () => {
        expect(myInvestorCashSummary(await cashFile())).toEqual({
            count: 5,
            from: '2026-10-02',
            to: '2026-10-10',
        });
    });

    it('goes to the account picked first, else the MyInvestor savings account', async () => {
        const file = await cashFile();
        const picked = account({ id: 'picked' });
        const savings = account({
            id: 'mi-savings',
            name: 'Remunerada',
            type: 'savings',
            bank: myInvestorBank,
        });
        const portfolio = account({
            id: 'mi-portfolio',
            name: 'MyInvestor · Portfolio',
            type: 'investment',
        });

        expect(
            defaultMyInvestorCashPlan(file, [picked, savings], 'picked').cash,
        ).toEqual({ kind: 'existing', accountId: 'picked' });
        expect(
            defaultMyInvestorCashPlan(file, [picked, savings, portfolio], null)
                .cash,
        ).toEqual({ kind: 'existing', accountId: 'mi-savings' });
        expect(defaultMyInvestorCashPlan(file, [picked], null).cash).toEqual({
            kind: 'create',
        });
    });

    it("uses the file's own balance on each of its days and carries it over the rest", async () => {
        const file = await cashFile();
        const balances = myInvestorCashBalancePayload(file, 123456, TODAY);
        const on = (date: string) =>
            balances.find((one) => one.balance_date === date)?.balance;

        expect(balances[0].balance_date).toBe('2026-10-02');
        expect(balances.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 123456,
        });
        expect(on('2026-10-02')).toBe(101248);
        expect(on('2026-10-03')).toBe(88778);
        expect(on('2026-10-04')).toBe(88778);
        expect(on('2026-10-05')).toBe(273778);
        expect(on('2026-10-10')).toBe(123456);
    });

    it('shows the movements newest first with their day-end balance and id', async () => {
        const rows = myInvestorCashPreviewRows(await cashFile(), 123456, TODAY);

        expect(rows[0]).toMatchObject({
            transaction_date: '2026-10-10',
            amount: -150322,
            balance: 123456,
        });
        expect(
            rows.every((row) => row.external_id?.startsWith('myinvestor-')),
        ).toBe(true);
    });
});

describe('endOfDayBalances', () => {
    const movement = (amount: number, balance: number): CashMovement => ({
        date: '2026-10-03',
        amount,
        balance,
        currency: 'EUR',
        description: 'Coffee',
        externalId: `id-${balance}`,
        kind: 'card',
    });

    it("finds the day's last row whichever order the file lists them in", () => {
        const first = movement(-100, 900);
        const second = movement(-200, 700);

        expect(endOfDayBalances([first, second]).get('2026-10-03')).toBe(700);
        expect(endOfDayBalances([second, first]).get('2026-10-03')).toBe(700);
    });
});

describe('MyInvestor securities import', () => {
    it('counts pension-plan orders toward invested', async () => {
        const file = await securitiesFile();

        expect(myInvestorInvestedToday(file, '2026-06-01')).toBe(151000);
        expect(myInvestorSecuritiesSummary(file)).toEqual({
            count: 9,
            from: '2026-01-10',
            to: '2026-06-01',
        });
    });

    it('is worth what was invested in the past and the typed value today', async () => {
        const file = await securitiesFile();
        const payload = myInvestorPortfolioPayload(file, 160000, TODAY);

        expect(payload[0]).toEqual({
            balance_date: '2026-01-10',
            balance: 105000,
            invested_amount: 105000,
        });
        expect(payload.at(-1)).toEqual({
            balance_date: TODAY,
            balance: 160000,
            invested_amount: 151000,
        });
        expect(myInvestorPortfolioPayload(file, null, TODAY).at(-1)).toEqual({
            balance_date: TODAY,
            balance: 151000,
            invested_amount: 151000,
        });
    });

    it('goes to the MyInvestor investment account when there is one', () => {
        const portfolio = account({
            id: 'mi-portfolio',
            name: 'MyInvestor · Portfolio',
            type: 'investment',
        });
        const savings = account({
            id: 'mi-savings',
            type: 'savings',
            bank: myInvestorBank,
        });

        expect(isMyInvestorAccount(portfolio)).toBe(true);
        expect(
            defaultMyInvestorSecuritiesPlan([savings, portfolio]).portfolio,
        ).toEqual({ kind: 'existing', accountId: 'mi-portfolio' });
        expect(defaultMyInvestorSecuritiesPlan([savings]).portfolio).toEqual({
            kind: 'create',
        });
    });
});
