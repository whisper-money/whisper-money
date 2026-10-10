import { netWorthContribution } from '@/lib/chart-calculations';
import {
    Account,
    AccountType,
    Bank,
    type CreditCardUsage,
} from '@/types/account';
import { formatDayFromDate, formatMonthFromYearMonth } from '@/utils/date';

export interface NetWorthEvolutionAccount {
    id: string;
    name: string;
    type: AccountType;
    currency_code: string;
    bank: Bank;
    banking_connection_id: string | null;
    invested_amount?: number | null;
    linked_loan_account_id?: string | null;
    hidden_on_dashboard?: boolean;
    archived_at?: string | null;
}

export interface OriginalAmount {
    amount: number;
    currency_code: string;
}

export interface NetWorthEvolutionData {
    data: Array<Record<string, string | number | OriginalAmount>>;
    accounts: Record<string, NetWorthEvolutionAccount>;
    currency_code: string;
}

export interface AccountWithMetrics extends Account {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    history: Array<{
        date: string;
        value: number;
        investedAmount?: number | null;
    }>;
    investedAmount: number | null;
    hidden_on_dashboard: boolean;
    archived_at: string | null;
    /** Set on a credit card while the credit card feature is on. */
    creditCardUsage?: CreditCardUsage;
}

/**
 * The currency a row's figures are drawn in: a credit card's usage stays in
 * the card's own currency, unconverted, while balances arrive converted to
 * the one on display.
 */
export function figuresCurrencyCode(
    account: Pick<AccountWithMetrics, 'currency_code' | 'creditCardUsage'>,
    displayCurrencyCode: string | undefined,
): string {
    return account.creditCardUsage
        ? account.currency_code
        : (displayCurrencyCode ?? account.currency_code);
}

/**
 * A credit card's row once its usage is known: the usage stands in for the
 * balance, and the sparkline follows what is in use day by day instead of a
 * balance nobody keeps.
 */
export function withCreditCardUsage<
    T extends Pick<AccountWithMetrics, 'id' | 'type' | 'history'>,
>(
    account: T,
    usageByAccount: Record<string, CreditCardUsage> | undefined,
    locale = 'en-US',
): T & { creditCardUsage?: CreditCardUsage } {
    const usage = usageByAccount?.[account.id];

    if (account.type !== 'credit_card' || !usage) {
        return account;
    }

    return {
        ...account,
        creditCardUsage: usage,
        history: usage.daily.map((day) => ({
            date: formatDayFromDate(day.date, locale),
            value: day.used,
        })),
    };
}

export function deriveAccountMetrics(
    netWorthEvolution: NetWorthEvolutionData,
    locale = 'en-US',
): AccountWithMetrics[] {
    const { data, accounts } = netWorthEvolution;

    if (data.length === 0 || Object.keys(accounts).length === 0) {
        return [];
    }

    return Object.values(accounts).map((account) => {
        const investedKey = account.id + '_invested';
        const history = data.map((point) => ({
            date: formatMonthFromYearMonth(point.month as string, locale),
            value:
                typeof point[account.id] === 'number'
                    ? netWorthContribution(
                          account.type,
                          point[account.id] as number,
                      )
                    : 0,
            investedAmount:
                investedKey in point
                    ? (point[investedKey] as number | null)
                    : undefined,
        }));

        const currentBalance = history[history.length - 1]?.value ?? 0;
        const previousBalance =
            history.length > 1 ? (history[history.length - 2]?.value ?? 0) : 0;

        return {
            id: account.id,
            name: account.name,
            type: account.type,
            currency_code: account.currency_code,
            bank: account.bank,
            banking_connection_id: account.banking_connection_id,
            linked_loan_account_id: account.linked_loan_account_id ?? null,
            currentBalance,
            previousBalance,
            diff: currentBalance - previousBalance,
            history,
            investedAmount: account.invested_amount ?? null,
            hidden_on_dashboard: account.hidden_on_dashboard ?? false,
            archived_at: account.archived_at ?? null,
        } as AccountWithMetrics;
    });
}
