import { store as storeAccount } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { type BrokerAccountRole } from '@/lib/broker-imports/broker-import';
import { dailyInvested, type BalancePoint } from '@/lib/broker-imports/series';
import { reportBrokerBalancesFailed } from '@/lib/broker-imports/telemetry';
import {
    type BrokerFileKind,
    type CashMovement,
    type Trade,
} from '@/lib/broker-imports/types';
import {
    storeBalancesInBulk,
    type BulkBalance,
    type ImportOutcome,
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

/**
 * Writes each account's daily balances, one account after the other, and
 * goes on to the next when one fails: a refused cash series must not cost
 * the portfolio its own. False when any of them failed; each failure goes
 * to Sentry with the account's role, never its name or the amounts.
 */
export async function writeBalanceSeries(
    kind: BrokerFileKind,
    series: {
        role: BrokerAccountRole;
        account: Account;
        balances: BulkBalance[];
    }[],
): Promise<boolean> {
    let allWritten = true;

    for (const { role, account, balances } of series) {
        try {
            await storeBalancesInBulk(account.id, balances);
        } catch (error) {
            console.error('Failed to import broker balances:', error);
            reportBrokerBalancesFailed(error, kind, {
                role,
                accountType: account.type,
                connected: isBankConnected(account),
                balances: balances.length,
            });
            allWritten = false;
        }
    }

    return allWritten;
}

/**
 * Whether the account is connected to the user's bank. Its movements and
 * balances come from the sync, so a broker file brings it nothing: the
 * file's own copies would land next to the synced ones as duplicates.
 */
export function isBankConnected(
    account: Pick<Account, 'banking_connection_id'> | undefined,
): boolean {
    return Boolean(account?.banking_connection_id);
}

/** What a run that brought no transactions reports, fresh each time. */
export function noTransactions(): ImportOutcome {
    return {
        imported: [],
        errors: [],
        successCount: 0,
        uncategorizedCount: 0,
    };
}

/**
 * The broker's bank, to make its accounts at. The banks list only holds the
 * banks the user made, so a broker known through a connected account is
 * found on that account instead.
 */
export function brokerBank(
    banks: Bank[],
    accounts: Account[],
    isBrokerBank: (bank: Pick<Bank, 'name'> | null) => boolean,
): Bank | null {
    return (
        banks.find(isBrokerBank) ??
        accounts
            .map((account) => account.bank ?? null)
            .find(
                (bank): bank is Bank => bank !== null && isBrokerBank(bank),
            ) ??
        null
    );
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
