import { accountHasBalance } from '@/hooks/use-owns-balance-updates';
import {
    resolveBrokerAccount,
    writeBalanceSeries,
} from '@/lib/broker-imports/broker-accounts';
import {
    type BrokerRunOptions,
    type BrokerRunResult,
} from '@/lib/broker-imports/broker-import';
import {
    isMyInvestorBank,
    MYINVESTOR_CASH_NAME,
    MYINVESTOR_PORTFOLIO_NAME,
    myInvestorCashBalancePayload,
    myInvestorCurrency,
    myInvestorPortfolioPayload,
    type MyInvestorCashImport,
    type MyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-import';
import { importTransactions } from '@/lib/transaction-import';
import { type Bank } from '@/types/account';

function myInvestorBank(banks: Bank[]): Bank | null {
    return banks.find(isMyInvestorBank) ?? null;
}

/**
 * Brings a MyInvestor current-account file in: makes the savings account
 * when chosen, the movements as transactions on it (categorised by the
 * user's rules, each with the id made from what it says), then its balance
 * for every day.
 */
export async function runMyInvestorCashImport(
    broker: MyInvestorCashImport,
    options: BrokerRunOptions,
): Promise<BrokerRunResult> {
    const { file, plan, today } = broker;
    const account = await resolveBrokerAccount(
        plan.cash,
        options.accounts,
        {
            name: MYINVESTOR_CASH_NAME,
            type: 'savings',
            currencyCode: myInvestorCurrency(file),
            bank: myInvestorBank(options.banks),
        },
        (created) => options.onAccountCreated?.('cash', created),
    );

    const outcome = await importTransactions({
        account,
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
        !accountHasBalance(account) ||
        (await writeBalanceSeries([
            {
                account,
                balances: myInvestorCashBalancePayload(
                    file,
                    plan.balanceToday,
                    today,
                ),
            },
        ]));

    return { outcome, balancesFailed: !balancesWritten };
}

/**
 * Brings a MyInvestor securities file in. It holds no cash, so it makes no
 * transactions: only the portfolio account (made when chosen) and its
 * balance for every day, worth what had been invested and, today, what the
 * user typed.
 */
export async function runMyInvestorSecuritiesImport(
    broker: MyInvestorSecuritiesImport,
    options: BrokerRunOptions,
): Promise<BrokerRunResult> {
    const { file, plan, today } = broker;
    const account = await resolveBrokerAccount(
        plan.portfolio,
        options.accounts,
        {
            name: MYINVESTOR_PORTFOLIO_NAME,
            type: 'investment',
            currencyCode: myInvestorCurrency(file),
            bank: myInvestorBank(options.banks),
        },
        (created) => options.onAccountCreated?.('portfolio', created),
    );

    const balancesWritten = await writeBalanceSeries([
        {
            account,
            balances: myInvestorPortfolioPayload(
                file,
                plan.currentValue,
                today,
            ),
        },
    ]);

    return {
        outcome: {
            imported: [],
            errors: [],
            successCount: 0,
            uncategorizedCount: 0,
        },
        balancesFailed: !balancesWritten,
    };
}
