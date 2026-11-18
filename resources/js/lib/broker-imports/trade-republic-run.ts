import { store as storeAccount } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { accountHasBalance } from '@/hooks/use-owns-balance-updates';
import {
    cashBalancePayload,
    isTradeRepublicBank,
    portfolioBalancePayload,
    TRADE_REPUBLIC_CASH_NAME,
    TRADE_REPUBLIC_PORTFOLIO_NAME,
    tradeRepublicCurrency,
    type AccountChoice,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import {
    importTransactions,
    storeBalancesInBulk,
    type ImportOutcome,
} from '@/lib/transaction-import';
import { type Account, type AccountType, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type ParsedTransaction } from '@/types/import';
import axios from 'axios';

/** Which of the two accounts of a Trade Republic import an account is. */
export type TradeRepublicRole = 'cash' | 'portfolio';

export interface TradeRepublicRunOptions {
    broker: TradeRepublicImport;
    /** The preview rows the user kept. */
    rows: ParsedTransaction[];
    accounts: Account[];
    categories: Category[];
    banks: Bank[];
    automationRules: AutomationRule[];
    today: string;
    onProgress?: (processed: number) => void;
    /** Told as soon as an account exists, so a retry uses it instead of making another. */
    onAccountCreated?: (role: TradeRepublicRole, account: Account) => void;
}

export interface TradeRepublicRunResult {
    outcome: ImportOutcome;
    /** Some daily balances could not be written: the transactions are in regardless. */
    balancesFailed: boolean;
}

/** What each account is called and what it is when the import makes it. */
const NEW_ACCOUNTS: Record<
    TradeRepublicRole,
    { name: string; type: AccountType }
> = {
    cash: { name: TRADE_REPUBLIC_CASH_NAME, type: 'checking' },
    portfolio: { name: TRADE_REPUBLIC_PORTFOLIO_NAME, type: 'investment' },
};

/**
 * A manual account, made through the same endpoint the accounts page uses,
 * at Trade Republic when the banks list knows it.
 */
async function createAccount(
    name: string,
    type: AccountType,
    currencyCode: string,
    banks: Bank[],
): Promise<Account> {
    const bank = banks.find(isTradeRepublicBank) ?? null;
    const response = await axios.post<Account>(storeAccount.url(), {
        name,
        type,
        currency_code: currencyCode,
        ...(bank ? { bank_id: bank.id } : {}),
    });

    return { ...response.data, bank };
}

async function resolveAccount(
    choice: AccountChoice,
    role: TradeRepublicRole,
    options: TradeRepublicRunOptions,
): Promise<Account> {
    if (choice.kind === 'existing') {
        const account = options.accounts.find(
            (candidate) => candidate.id === choice.accountId,
        );

        if (!account) {
            throw new Error('Selected account not found');
        }

        return account;
    }

    const account = await createAccount(
        NEW_ACCOUNTS[role].name,
        NEW_ACCOUNTS[role].type,
        tradeRepublicCurrency(options.broker.file),
        options.banks,
    );

    options.onAccountCreated?.(role, account);

    return account;
}

/** Writes the daily balances of both accounts; false when a request failed. */
async function writeBalances(
    cashAccount: Account,
    portfolioAccount: Account | null,
    options: TradeRepublicRunOptions,
): Promise<boolean> {
    const { file, plan } = options.broker;

    try {
        if (accountHasBalance(cashAccount)) {
            await storeBalancesInBulk(
                cashAccount.id,
                cashBalancePayload(file, plan.balanceToday, options.today),
            );
        }

        if (portfolioAccount) {
            await storeBalancesInBulk(
                portfolioAccount.id,
                portfolioBalancePayload(file, plan.currentValue, options.today),
            );
        }

        return true;
    } catch (error) {
        console.error('Failed to import broker balances:', error);

        return false;
    }
}

/**
 * Brings a Trade Republic file in: makes the accounts chosen to be created,
 * the cash movements as transactions on the cash account (categorised by the
 * user's rules, with Trade Republic's id on each), then the cash account's
 * balance for every day and, when the portfolio is imported, what it held.
 */
export async function runTradeRepublicImport(
    options: TradeRepublicRunOptions,
): Promise<TradeRepublicRunResult> {
    const { plan } = options.broker;
    const cashAccount = await resolveAccount(plan.cash, 'cash', options);
    const portfolioAccount =
        plan.portfolio.kind === 'skip'
            ? null
            : await resolveAccount(plan.portfolio, 'portfolio', options);

    const outcome = await importTransactions({
        account: cashAccount,
        rows: options.rows,
        categories: options.categories,
        accounts: options.accounts,
        banks: options.banks,
        automationRules: options.automationRules,
        onProgress: options.onProgress,
        writeRowBalances: false,
    });

    const balancesWritten =
        outcome.successCount === 0 ||
        (await writeBalances(cashAccount, portfolioAccount, options));

    return { outcome, balancesFailed: !balancesWritten };
}
