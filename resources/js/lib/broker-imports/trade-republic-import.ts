import {
    cashBalances,
    computedCashBalance,
    dailyInvested,
} from '@/lib/broker-imports/series';
import { type TradeRepublicFile } from '@/lib/broker-imports/trade-republic';
import { type BulkBalance } from '@/lib/transaction-import';
import {
    filterTransactionalAccounts,
    supportsInvestedAmount,
    type Account,
    type Bank,
} from '@/types/account';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';

/** Where a part of the file goes: an account the user has, or one made for it on import. */
export type AccountChoice =
    | { kind: 'existing'; accountId: UUID }
    | { kind: 'create' };

/** The portfolio can also be left out of the import altogether. */
export type PortfolioChoice = AccountChoice | { kind: 'skip' };

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
    return (
        bank !== null &&
        bank.name.toLowerCase().replace(/\s+/g, '') === 'traderepublic'
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

/** The accounts the cash can go to. */
export function cashAccountOptions(accounts: Account[]): Account[] {
    return filterTransactionalAccounts(accounts);
}

/** The accounts the portfolio can go to: those that keep what was invested. */
export function portfolioAccountOptions(accounts: Account[]): Account[] {
    return accounts.filter(
        (account) => !account.archived_at && supportsInvestedAmount(account),
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
                account.type === 'checking' &&
                isTradeRepublicBank(account.bank),
        );
    const portfolioAccount = portfolioAccountOptions(accounts).find(
        (account) =>
            account.type === 'investment' && isTradeRepublicBank(account.bank),
    );

    return {
        cash: cashAccount
            ? { kind: 'existing', accountId: cashAccount.id }
            : { kind: 'create' },
        balanceToday: computedCashBalance(file.cash),
        portfolio: portfolioAccount
            ? { kind: 'existing', accountId: portfolioAccount.id }
            : { kind: 'create' },
        currentValue: null,
    };
}

/** What had been invested at the end of today, in minor units. */
export function investedToday(file: TradeRepublicFile, today: string): number {
    return dailyInvested(file.trades, today).at(-1)?.investedMinor ?? 0;
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
    const balanceByDay = new Map(
        cashBalances(file.cash, balanceToday, today).map((point) => [
            point.date,
            point.balanceMinor,
        ]),
    );

    return file.cash
        .map((movement) => ({
            transaction_date: movement.date,
            description: movement.description,
            amount: movement.amount,
            currency_code: movement.currency,
            balance: balanceByDay.get(movement.date) ?? null,
            external_id: movement.externalId,
        }))
        .sort((a, b) => b.transaction_date.localeCompare(a.transaction_date));
}

/** One balance per day on the cash account, from the first movement to today. */
export function cashBalancePayload(
    file: TradeRepublicFile,
    balanceToday: number,
    today: string,
): BulkBalance[] {
    return cashBalances(file.cash, balanceToday, today).map((point) => ({
        balance_date: point.date,
        balance: point.balanceMinor,
    }));
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
    return dailyInvested(file.trades, today).map((point) => ({
        balance_date: point.date,
        balance:
            point.date === today && currentValue !== null
                ? currentValue
                : point.investedMinor,
        invested_amount: point.investedMinor,
    }));
}
