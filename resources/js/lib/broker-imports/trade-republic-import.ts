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
    type PortfolioChoice,
} from '@/lib/broker-imports/broker-accounts';
import { cashBalances, computedCashBalance } from '@/lib/broker-imports/series';
import { type TradeRepublicFile } from '@/lib/broker-imports/trade-republic';
import { type BrokerLink } from '@/lib/broker-imports/types';
import { type BulkBalance } from '@/lib/transaction-import';
import { type Account, type Bank } from '@/types/account';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';

/** What the user decided on the Trade Republic step. */
export interface TradeRepublicPlan {
    cash: AccountChoice;
    /** Today's cash balance, in minor units: the daily balances are rebuilt backwards from it. */
    balanceToday: number;
    portfolio: PortfolioChoice;
    /** What the portfolio is worth today, in minor units; null reads as what was invested. */
    currentValue: number | null;
}

/** A Trade Republic file as the drawer holds it between steps. */
export interface TradeRepublicImport {
    kind: 'trade_republic';
    file: TradeRepublicFile;
    /** The day the file was read, `YYYY-MM-DD`: the balances run up to it. */
    today: string;
    plan: TradeRepublicPlan;
    /** The import of this broker remembered from last time, when there is one. */
    memory?: BrokerLink | null;
}

/** What the upload step tells about a recognised file. */
export interface TradeRepublicSummary {
    /** Rows of the file that moved cash or securities, each counted once. */
    movements: number;
    /** `YYYY-MM-DD` of the first and last of them, or null for an empty file. */
    from: string | null;
    to: string | null;
}

export const TRADE_REPUBLIC_CASH_NAME = 'Trade Republic';
export const TRADE_REPUBLIC_PORTFOLIO_NAME = 'Trade Republic · Portfolio';

const DEFAULT_CURRENCY = 'EUR';

/** Whether a bank is Trade Republic, by its name as the banks list writes it. */
export function isTradeRepublicBank(bank: Pick<Bank, 'name'> | null): boolean {
    return bankNamed(bank, 'traderepublic');
}

/**
 * Whether an account is kept at Trade Republic: by its bank or, when the
 * banks list has no Trade Republic to attach, by the name this import gives
 * the accounts it creates.
 */
export function isTradeRepublicAccount(
    account: Pick<Account, 'bank' | 'name'>,
): boolean {
    return (
        isTradeRepublicBank(account.bank) ||
        account.name === TRADE_REPUBLIC_CASH_NAME ||
        account.name === TRADE_REPUBLIC_PORTFOLIO_NAME
    );
}

export function tradeRepublicSummary(
    file: TradeRepublicFile,
): TradeRepublicSummary {
    const dates = new Map<string, string>();

    for (const row of [...file.cash, ...file.trades]) {
        dates.set(row.externalId, row.date);
    }

    const sorted = [...dates.values()].sort();

    return {
        movements: dates.size,
        from: sorted[0] ?? null,
        to: sorted.at(-1) ?? null,
    };
}

/** The currency the file is kept in: its first movement's, or the euro. */
export function tradeRepublicCurrency(file: TradeRepublicFile): string {
    return (
        file.cash[0]?.currency ?? file.trades[0]?.currency ?? DEFAULT_CURRENCY
    );
}

/**
 * Where each part goes before the user changes anything: the account picked
 * on the first step, else one the user already keeps at Trade Republic, else
 * a new one.
 */
export function defaultTradeRepublicPlan(
    file: TradeRepublicFile,
    accounts: Account[],
    selectedAccountId: UUID | null,
): TradeRepublicPlan {
    const cashAccount =
        cashAccountOptions(accounts).find(
            (account) => account.id === selectedAccountId,
        ) ??
        cashAccountOptions(accounts).find(
            (account) =>
                account.type === 'checking' && isTradeRepublicAccount(account),
        );
    const portfolioAccount = portfolioAccountOptions(accounts).find(
        (account) =>
            account.type === 'investment' && isTradeRepublicAccount(account),
    );

    return {
        cash: choiceOf(cashAccount),
        balanceToday: computedCashBalance(file.cash),
        portfolio: choiceOf(portfolioAccount),
        currentValue: null,
    };
}

/** What had been invested at the end of today, in minor units. */
export function investedToday(file: TradeRepublicFile, today: string): number {
    return investedOn(file.trades, today);
}

/**
 * The cash movements as preview rows, newest first, each with the balance its
 * day ended on and the id Trade Republic gave it, so a second import of the
 * same file is recognised.
 */
export function tradeRepublicPreviewRows(
    file: TradeRepublicFile,
    balanceToday: number,
    today: string,
): ParsedTransaction[] {
    return movementPreviewRows(
        file.cash,
        cashBalances(file.cash, balanceToday, today),
    );
}

/** One balance per day on the cash account, from the first movement to today. */
export function cashBalancePayload(
    file: TradeRepublicFile,
    balanceToday: number,
    today: string,
): BulkBalance[] {
    return balancePayload(cashBalances(file.cash, balanceToday, today));
}

/**
 * One balance per day on the portfolio account. With no price to go by, the
 * past is worth what had been invested; today is worth what the user typed,
 * or the same.
 */
export function portfolioBalancePayload(
    file: TradeRepublicFile,
    currentValue: number | null,
    today: string,
): BulkBalance[] {
    return investedBalancePayload(file.trades, currentValue, today);
}
