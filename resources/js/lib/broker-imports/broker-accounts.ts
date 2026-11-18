import { store as storeAccount } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { dailyInvested, type BalancePoint } from '@/lib/broker-imports/series';
import { type CashMovement, type Trade } from '@/lib/broker-imports/types';
import {
    storeBalancesInBulk,
    type BulkBalance,
} from '@/lib/transaction-import';
import {
    filterTransactionalAccounts,
    supportsInvestedAmount,
    type Account,
    type AccountType,
    type Bank,
} from '@/types/account';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';
import axios from 'axios';

/*
 * What every broker import does with accounts and their balances, whichever
 * broker the file comes from.
 */

/** Where a part of the file goes: an account the user has, or one made for it on import. */
export type AccountChoice =
    | { kind: 'existing'; accountId: UUID }
    | { kind: 'create' };

/** The portfolio can also be left out of the import altogether. */
export type PortfolioChoice = AccountChoice | { kind: 'skip' };

/** The account a choice already points at, or null while it is still to be made. */
export function chosenAccountId(choice: PortfolioChoice): UUID | null {
    return choice.kind === 'existing' ? choice.accountId : null;
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

/** A choice of the account found, or of making one when there is none. */
export function choiceOf(account: Account | undefined): AccountChoice {
    return account
        ? { kind: 'existing', accountId: account.id }
        : { kind: 'create' };
}

/** What had been invested at the end of today, in minor units. */
export function investedOn(trades: Trade[], today: string): number {
    return dailyInvested(trades, today).at(-1)?.investedMinor ?? 0;
}

/**
 * Cash movements as preview rows, newest first, each with the balance its
 * day ended on and the id the import gives it, so a second import of the
 * same file is recognised.
 */
export function movementPreviewRows(
    movements: CashMovement[],
    balances: BalancePoint[],
): ParsedTransaction[] {
    const balanceByDay = new Map(
        balances.map((point) => [point.date, point.balanceMinor]),
    );

    return movements
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

/** A balance series as the bulk endpoint takes it. */
export function balancePayload(points: BalancePoint[]): BulkBalance[] {
    return points.map((point) => ({
        balance_date: point.date,
        balance: point.balanceMinor,
    }));
}

/**
 * One balance per day on a portfolio account. With no price to go by, the
 * past is worth what had been invested; today is worth what the user typed,
 * or the same.
 */
export function investedBalancePayload(
    trades: Trade[],
    currentValue: number | null,
    today: string,
): BulkBalance[] {
    return dailyInvested(trades, today).map((point) => ({
        balance_date: point.date,
        balance:
            point.date === today && currentValue !== null
                ? currentValue
                : point.investedMinor,
        invested_amount: point.investedMinor,
    }));
}

export interface NewBrokerAccount {
    name: string;
    type: AccountType;
    currencyCode: string;
    /** The broker's bank in the banks list, when it has one. */
    bank: Bank | null;
}

/**
 * The account a choice points at: the user's own, or a manual one made
 * through the same endpoint the accounts page uses. `onCreated` hears of a
 * new one at once, so a retry uses it instead of making another.
 */
export async function resolveBrokerAccount(
    choice: AccountChoice,
    accounts: Account[],
    newAccount: NewBrokerAccount,
    onCreated?: (account: Account) => void,
): Promise<Account> {
    if (choice.kind === 'existing') {
        const account = accounts.find(
            (candidate) => candidate.id === choice.accountId,
        );

        if (!account) {
            throw new Error('Selected account not found');
        }

        return account;
    }

    const { bank } = newAccount;
    const response = await axios.post<Account>(storeAccount.url(), {
        name: newAccount.name,
        type: newAccount.type,
        currency_code: newAccount.currencyCode,
        ...(bank ? { bank_id: bank.id } : {}),
    });
    const account = { ...response.data, bank };

    onCreated?.(account);

    return account;
}

/** Writes each account's daily balances, in order; false when a request failed. */
export async function writeBalanceSeries(
    series: { account: Account; balances: BulkBalance[] }[],
): Promise<boolean> {
    try {
        for (const { account, balances } of series) {
            await storeBalancesInBulk(account.id, balances);
        }

        return true;
    } catch (error) {
        console.error('Failed to import broker balances:', error);

        return false;
    }
}

/** Whether a bank is the broker's, by its name as the banks list writes it. */
export function bankNamed(
    bank: Pick<Bank, 'name'> | null,
    compactName: string,
): boolean {
    return (
        bank !== null &&
        bank.name.toLowerCase().replace(/\s+/g, '') === compactName
    );
}
