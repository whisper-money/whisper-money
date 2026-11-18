import {
    balancePayload,
    bankNamed,
    cashAccountOptions,
    choiceOf,
    investedBalancePayload,
    investedOn,
    movementPreviewRows,
    portfolioAccountOptions,
    type AccountChoice,
} from '@/lib/broker-imports/broker-accounts';
import { DEFAULT_CURRENCY } from '@/lib/broker-imports/grid';
import { type MyInvestorCashFile } from '@/lib/broker-imports/myinvestor-cash';
import { type MyInvestorSecuritiesFile } from '@/lib/broker-imports/myinvestor-securities';
import {
    cashBalances,
    computedCashBalance,
    endOfDayBalances,
    type BalancePoint,
} from '@/lib/broker-imports/series';
import { type Trade } from '@/lib/broker-imports/types';
import { type BulkBalance } from '@/lib/transaction-import';
import { type Account, type Bank } from '@/types/account';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';

/*
 * MyInvestor hands out one file per product: the current account's
 * movements, and the securities operations in another. Each is its own
 * import, with its own account.
 */

/** What the user decided on the MyInvestor current-account step. */
export interface MyInvestorCashPlan {
    cash: AccountChoice;
    /** Today's balance, in minor units: the file's last one when it has them. */
    balanceToday: number;
}

/** A MyInvestor current-account file as the drawer holds it between steps. */
export interface MyInvestorCashImport {
    kind: 'myinvestor_cash';
    file: MyInvestorCashFile;
    /** The day the file was read, `YYYY-MM-DD`: the balances run up to it. */
    today: string;
    plan: MyInvestorCashPlan;
}

/** What the user decided on the MyInvestor securities step. */
export interface MyInvestorSecuritiesPlan {
    portfolio: AccountChoice;
    /** What the portfolio is worth today, in minor units; null reads as what was invested. */
    currentValue: number | null;
}

/** A MyInvestor securities file as the drawer holds it between steps. */
export interface MyInvestorSecuritiesImport {
    kind: 'myinvestor_securities';
    file: MyInvestorSecuritiesFile;
    today: string;
    plan: MyInvestorSecuritiesPlan;
}

/** What the upload step tells about a recognised file. */
export interface MyInvestorSummary {
    count: number;
    /** `YYYY-MM-DD` of the first and last row, or null for an empty file. */
    from: string | null;
    to: string | null;
}

export const MYINVESTOR_CASH_NAME = 'MyInvestor';
export const MYINVESTOR_PORTFOLIO_NAME = 'MyInvestor · Portfolio';

/** Whether a bank is MyInvestor, by its name as the banks list writes it. */
export function isMyInvestorBank(bank: Pick<Bank, 'name'> | null): boolean {
    return bankNamed(bank, 'myinvestor');
}

/**
 * Whether an account is kept at MyInvestor: by its bank or, when the banks
 * list has no MyInvestor to attach, by the name this import gives the
 * accounts it creates.
 */
export function isMyInvestorAccount(
    account: Pick<Account, 'bank' | 'name'>,
): boolean {
    return (
        isMyInvestorBank(account.bank) ||
        account.name === MYINVESTOR_CASH_NAME ||
        account.name === MYINVESTOR_PORTFOLIO_NAME
    );
}

function summaryOf(rows: { date: string }[]): MyInvestorSummary {
    const sorted = rows.map((row) => row.date).sort();

    return {
        count: rows.length,
        from: sorted[0] ?? null,
        to: sorted.at(-1) ?? null,
    };
}

export function myInvestorCashSummary(
    file: MyInvestorCashFile,
): MyInvestorSummary {
    return summaryOf(file.movements);
}

/** The orders that count toward invested: on a security, or a pension plan's. */
export function investedOrders(file: MyInvestorSecuritiesFile): Trade[] {
    return [...file.trades, ...file.unassigned];
}

export function myInvestorSecuritiesSummary(
    file: MyInvestorSecuritiesFile,
): MyInvestorSummary {
    return summaryOf(investedOrders(file));
}

/** The currency a file is kept in: its first row's, or the euro. */
export function myInvestorCurrency(
    file: MyInvestorCashFile | MyInvestorSecuritiesFile,
): string {
    const first =
        'movements' in file ? file.movements[0] : investedOrders(file)[0];

    return first?.currency ?? DEFAULT_CURRENCY;
}

/** The balance the file ends on, when its rows carry one. */
export function fileBalanceToday(file: MyInvestorCashFile): number | null {
    const balances = endOfDayBalances(file.movements);
    const lastDay = [...balances.keys()].sort().at(-1);

    return lastDay === undefined ? null : (balances.get(lastDay) ?? null);
}

/**
 * Where the current account goes before the user changes anything: the
 * account picked on the first step, else one the user already keeps at
 * MyInvestor (a savings one first), else a new one.
 */
export function defaultMyInvestorCashPlan(
    file: MyInvestorCashFile,
    accounts: Account[],
    selectedAccountId: UUID | null,
): MyInvestorCashPlan {
    const options = cashAccountOptions(accounts);
    const atMyInvestor = options.filter(isMyInvestorAccount);
    const cashAccount =
        options.find((account) => account.id === selectedAccountId) ??
        atMyInvestor.find((account) => account.type === 'savings') ??
        atMyInvestor[0];

    return {
        cash: choiceOf(cashAccount),
        balanceToday:
            fileBalanceToday(file) ?? computedCashBalance(file.movements),
    };
}

/** Where the portfolio goes by default: the MyInvestor investment account, else a new one. */
export function defaultMyInvestorSecuritiesPlan(
    accounts: Account[],
): MyInvestorSecuritiesPlan {
    return {
        portfolio: choiceOf(
            portfolioAccountOptions(accounts).find(
                (account) =>
                    account.type === 'investment' &&
                    isMyInvestorAccount(account),
            ),
        ),
        currentValue: null,
    };
}

/**
 * The balance each day ended on, up to today: the file's own on the days it
 * has one, rebuilt backwards from today's on the rest.
 */
function myInvestorCashBalances(
    file: MyInvestorCashFile,
    balanceToday: number,
    today: string,
): BalancePoint[] {
    return cashBalances(
        file.movements,
        balanceToday,
        today,
        endOfDayBalances(file.movements),
    );
}

/**
 * The movements as preview rows, newest first, each with the balance its day
 * ended on and an id made from what it says, so a second import of the same
 * file is recognised.
 */
export function myInvestorCashPreviewRows(
    file: MyInvestorCashFile,
    balanceToday: number,
    today: string,
): ParsedTransaction[] {
    return movementPreviewRows(
        file.movements,
        myInvestorCashBalances(file, balanceToday, today),
    );
}

/** One balance per day on the current account, from the first movement to today. */
export function myInvestorCashBalancePayload(
    file: MyInvestorCashFile,
    balanceToday: number,
    today: string,
): BulkBalance[] {
    return balancePayload(myInvestorCashBalances(file, balanceToday, today));
}

/** What had been invested at the end of today, pension-plan orders included. */
export function myInvestorInvestedToday(
    file: MyInvestorSecuritiesFile,
    today: string,
): number {
    return investedOn(investedOrders(file), today);
}

/** One balance per day on the portfolio account, as invested, today at its value. */
export function myInvestorPortfolioPayload(
    file: MyInvestorSecuritiesFile,
    currentValue: number | null,
    today: string,
): BulkBalance[] {
    return investedBalancePayload(investedOrders(file), currentValue, today);
}
