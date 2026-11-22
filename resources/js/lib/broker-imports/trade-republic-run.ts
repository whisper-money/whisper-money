import { ownsBalanceUpdates } from '@/hooks/use-owns-balance-updates';
import {
    brokerBank,
    isBankConnected,
    noTransactions,
    resolveBrokerAccount,
    writeBalanceSeries,
    type AccountChoice,
} from '@/lib/broker-imports/broker-accounts';
import {
    cashBalancePayload,
    isTradeRepublicBank,
    portfolioBalancePayload,
    TRADE_REPUBLIC_CASH_NAME,
    TRADE_REPUBLIC_PORTFOLIO_NAME,
    tradeRepublicCurrency,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import {
    importTransactions,
    type ImportOutcome,
} from '@/lib/transaction-import';
import { type Account, type AccountType, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type ParsedTransaction } from '@/types/import';

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
    onProgress?: (processed: number) => void;
    /** Told as soon as an account exists, so a retry uses it instead of making another. */
    onAccountCreated?: (role: TradeRepublicRole, account: Account) => void;
}

export interface TradeRepublicRunResult {
    outcome: ImportOutcome;
    /** Some daily balances could not be written: the transactions are in regardless. */
    balancesFailed: boolean;
    /** The accounts the file went to, made ones included; no portfolio when skipped. */
    accounts: { cash: Account; portfolio?: Account };
}

/** What each account is called and what it is when the import makes it. */
const NEW_ACCOUNTS: Record<
    TradeRepublicRole,
    { name: string; type: AccountType }
> = {
    cash: { name: TRADE_REPUBLIC_CASH_NAME, type: 'checking' },
    portfolio: { name: TRADE_REPUBLIC_PORTFOLIO_NAME, type: 'investment' },
};

/** The account a choice points at, made at Trade Republic when the banks list knows it. */
function resolveAccount(
    choice: AccountChoice,
    role: TradeRepublicRole,
    options: TradeRepublicRunOptions,
): Promise<Account> {
    return resolveBrokerAccount(
        choice,
        options.accounts,
        {
            ...NEW_ACCOUNTS[role],
            currencyCode: tradeRepublicCurrency(options.broker.file),
            bank: brokerBank(
                options.banks,
                options.accounts,
                isTradeRepublicBank,
            ),
        },
        (account) => options.onAccountCreated?.(role, account),
    );
}

/**
 * Writes the daily balances of both accounts; false when a request failed.
 * The cash account's are left out when its balance is not the app's to
 * write, as on an account connected to the bank.
 */
function writeBalances(
    cashAccount: Account,
    portfolioAccount: Account | null,
    options: TradeRepublicRunOptions,
): Promise<boolean> {
    const { file, plan, today } = options.broker;

    return writeBalanceSeries('trade_republic', [
        ...(ownsBalanceUpdates(cashAccount)
            ? [
                  {
                      role: 'cash' as const,
                      account: cashAccount,
                      balances: cashBalancePayload(
                          file,
                          plan.balanceToday,
                          today,
                      ),
                  },
              ]
            : []),
        ...(portfolioAccount
            ? [
                  {
                      role: 'portfolio' as const,
                      account: portfolioAccount,
                      balances: portfolioBalancePayload(
                          file,
                          plan.currentValue,
                          today,
                      ),
                  },
              ]
            : []),
    ]);
}

/**
 * Brings a Trade Republic file in: makes the accounts chosen to be created,
 * the cash movements as transactions on the cash account (categorised by the
 * user's rules, with Trade Republic's id on each), then the cash account's
 * balance for every day and, when the portfolio is imported, what it held.
 * A cash account connected to the bank gets neither movements nor
 * balances, the sync bringing both: only the portfolio comes in.
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

    const cashSynced = isBankConnected(cashAccount);
    const outcome = cashSynced
        ? noTransactions()
        : await importTransactions({
              account: cashAccount,
              rows: options.rows,
              categories: options.categories,
              accounts: options.accounts,
              banks: options.banks,
              automationRules: options.automationRules,
              onProgress: options.onProgress,
              writeRowBalances: false,
          });

    // Nothing new came into a cash account of the app's: its balances stand.
    // With the movements left to the sync, the portfolio is all there is.
    const nothingToWrite = !cashSynced && outcome.successCount === 0;
    const balancesWritten =
        nothingToWrite ||
        (await writeBalances(cashAccount, portfolioAccount, options));

    return {
        outcome,
        balancesFailed: !balancesWritten,
        accounts: {
            cash: cashAccount,
            ...(portfolioAccount ? { portfolio: portfolioAccount } : {}),
        },
    };
}
