import { AccountType } from '@/types/account';

/**
 * Account types that reduce net worth (liabilities).
 *
 * A credit card is deliberately NOT a liability: its balance is spendable
 * credit the user treats as an asset, so it adds to net worth like a regular
 * account. Only loans (debt that must be repaid) subtract.
 */
export const LIABILITY_TYPES: AccountType[] = ['loan'];

/**
 * Check if an account type is a liability
 */
export function isLiabilityType(type: AccountType): boolean {
    return LIABILITY_TYPES.includes(type);
}

/**
 * Get the sign multiplier for an account type
 * Assets are positive (+1), Liabilities are negative (-1)
 */
export function getAccountSign(type: AccountType): 1 | -1 {
    return isLiabilityType(type) ? -1 : 1;
}

/**
 * Signed contribution of a raw account balance to net worth.
 *
 * Liabilities are stored as positive magnitudes, so they always subtract.
 * Assets keep their real sign, so a genuinely negative asset balance (e.g. an
 * overdrawn checking account) correctly reduces net worth instead of being
 * flipped positive and added.
 */
export function netWorthContribution(
    type: AccountType,
    balance: number,
): number {
    return isLiabilityType(type) ? -Math.abs(balance) : balance;
}

/**
 * Data point representing net worth for a month
 */
export interface MonthDataPoint {
    month: string;
    timestamp?: number;
    value: number;
}

/**
 * Data point with percentage change
 */
export interface PercentDataPoint extends MonthDataPoint {
    percent: number | null;
}

/**
 * Data point with absolute change
 */
export interface ChangeDataPoint extends MonthDataPoint {
    change: number | null;
}

/**
 * Account info for calculations
 */
export interface AccountInfo {
    id: string;
    type: AccountType;
    currency_code: string;
}

export interface NetWorthSeriesOptions {
    includeLoanAccounts?: boolean;
    includeRealEstateAccounts?: boolean;
}

/**
 * Compute net worth series from raw balance data
 * Handles sign correctly: assets add to NW, liabilities subtract
 */
export function computeNetWorthSeries(
    data: Array<Record<string, string | number>>,
    accounts: Record<string, AccountInfo>,
    options: NetWorthSeriesOptions = {},
): MonthDataPoint[] {
    if (!data || data.length === 0) {
        return [];
    }

    const includeLoanAccounts = options.includeLoanAccounts ?? true;
    const includeRealEstateAccounts = options.includeRealEstateAccounts ?? true;

    const accountIds = Object.entries(accounts)
        .filter(([, account]) => includeLoanAccounts || account.type !== 'loan')
        .filter(
            ([, account]) =>
                includeRealEstateAccounts || account.type !== 'real_estate',
        )
        .map(([id]) => id);

    return data.map((point) => {
        const month = point.month as string;
        const timestamp = point.timestamp as number | undefined;

        const totalNetWorth = accountIds.reduce((sum, id) => {
            const balance = point[id];
            if (typeof balance !== 'number') return sum;

            const account = accounts[id];
            if (!account) return sum + balance;

            const sign = getAccountSign(account.type);
            const normalizedBalance = sign === -1 ? Math.abs(balance) : balance;

            return sum + sign * normalizedBalance;
        }, 0);

        return {
            month,
            timestamp,
            value: totalNetWorth,
        };
    });
}

/**
 * Compute delta series (change from previous month)
 */
export function computeDeltaSeries(
    nwSeries: MonthDataPoint[],
): ChangeDataPoint[] {
    return nwSeries.map((point, index) => {
        if (index === 0) {
            return { ...point, change: null };
        }

        const previousValue = nwSeries[index - 1].value;
        return {
            ...point,
            change: point.value - previousValue,
        };
    });
}

/**
 * Compute month-over-month percentage change
 */
export function computeMoMPercent(
    nwSeries: MonthDataPoint[],
): PercentDataPoint[] {
    return nwSeries.map((point, index) => {
        if (index === 0) {
            return { ...point, percent: null };
        }

        const previousValue = nwSeries[index - 1].value;

        // Guard against division by zero or undefined previous value
        if (previousValue === 0) {
            return { ...point, percent: null };
        }

        const percentChange =
            ((point.value - previousValue) / Math.abs(previousValue)) * 100;

        return { ...point, percent: percentChange };
    });
}

/**
 * Format percentage value for display
 */
export function formatPercentValue(
    value: number | null,
    options?: { decimals?: number },
): string {
    if (value === null) return '—';

    const decimals = options?.decimals ?? 1;
    const formatted = value.toFixed(decimals);
    const sign = value > 0 ? '+' : '';

    return `${sign}${formatted}%`;
}
