import { AccountType } from '@/types/account';

/**
 * Account types that reduce net worth (liabilities)
 */
export const LIABILITY_TYPES: AccountType[] = ['credit_card', 'loan'];

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
 * Waterfall chart data point
 */
export interface WaterfallDataPoint {
    name: string;
    value: number;
    type: 'start' | 'change' | 'end';
    month?: string;
}

/**
 * Account info for calculations
 */
export interface AccountInfo {
    id: string;
    type: AccountType;
    currency_code: string;
}

/**
 * Optional flow data for waterfall calculations
 */
export interface MonthlyFlows {
    month: string;
    inflows: number;
    outflows: number;
    adjustments: number;
}

/**
 * Compute net worth series from raw balance data
 * Handles sign correctly: assets add to NW, liabilities subtract
 */
export function computeNetWorthSeries(
    data: Array<Record<string, string | number>>,
    accounts: Record<string, AccountInfo>,
): MonthDataPoint[] {
    if (!data || data.length === 0) {
        return [];
    }

    const accountIds = Object.keys(accounts);

    return data.map((point) => {
        const month = point.month as string;
        const timestamp = point.timestamp as number | undefined;

        const totalNetWorth = accountIds.reduce((sum, id) => {
            const balance = point[id];
            if (typeof balance !== 'number') return sum;

            const account = accounts[id];
            if (!account) return sum + balance;

            const sign = getAccountSign(account.type);
            return sum + sign * balance;
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
 * Compute year-over-year percentage change
 * Returns null for first 12 months where YoY is not available
 */
export function computeYoYPercent(
    nwSeries: MonthDataPoint[],
): PercentDataPoint[] {
    return nwSeries.map((point, index) => {
        if (index < 12) {
            return { ...point, percent: null };
        }

        const yearAgoValue = nwSeries[index - 12].value;

        if (yearAgoValue === 0) {
            return { ...point, percent: null };
        }

        const percentChange =
            ((point.value - yearAgoValue) / Math.abs(yearAgoValue)) * 100;

        return { ...point, percent: percentChange };
    });
}

/**
 * Compute rolling 12-month absolute change
 */
export function computeRolling12mChange(
    nwSeries: MonthDataPoint[],
): ChangeDataPoint[] {
    return nwSeries.map((point, index) => {
        if (index < 12) {
            return { ...point, change: null };
        }

        const yearAgoValue = nwSeries[index - 12].value;
        return {
            ...point,
            change: point.value - yearAgoValue,
        };
    });
}

/**
 * Compute rolling 12-month percentage change
 */
export function computeRolling12mPercent(
    nwSeries: MonthDataPoint[],
): PercentDataPoint[] {
    return nwSeries.map((point, index) => {
        if (index < 12) {
            return { ...point, percent: null };
        }

        const yearAgoValue = nwSeries[index - 12].value;

        if (yearAgoValue === 0) {
            return { ...point, percent: null };
        }

        const percentChange =
            ((point.value - yearAgoValue) / Math.abs(yearAgoValue)) * 100;

        return { ...point, percent: percentChange };
    });
}

/**
 * Compute waterfall series for a single month transition
 * Simplified version: Start -> Change -> End
 */
export function computeWaterfallSeries(
    nwSeries: MonthDataPoint[],
    monthIndex?: number,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    flows?: MonthlyFlows[],
): WaterfallDataPoint[] {
    if (nwSeries.length < 2) {
        return [];
    }

    // Default to last month transition
    const targetIndex = monthIndex ?? nwSeries.length - 1;

    if (targetIndex < 1 || targetIndex >= nwSeries.length) {
        return [];
    }

    const startPoint = nwSeries[targetIndex - 1];
    const endPoint = nwSeries[targetIndex];
    const change = endPoint.value - startPoint.value;

    // Simplified waterfall without flow breakdown
    // TODO: Implement flow breakdown when flows data is available
    return [
        {
            name: 'Start',
            value: startPoint.value,
            type: 'start' as const,
            month: startPoint.month,
        },
        {
            name: 'Change',
            value: change,
            type: 'change' as const,
            month: endPoint.month,
        },
        {
            name: 'End',
            value: endPoint.value,
            type: 'end' as const,
            month: endPoint.month,
        },
    ];
}

/**
 * Check if log scale can be used (all values must be positive)
 */
export function canUseLogScale(series: MonthDataPoint[]): boolean {
    if (series.length === 0) return false;
    return series.every((point) => point.value > 0);
}

/**
 * Get warning message for log scale if it cannot be used
 */
export function getLogScaleWarning(series: MonthDataPoint[]): string | null {
    if (series.length === 0) {
        return 'No data available';
    }

    const hasZero = series.some((point) => point.value === 0);
    const hasNegative = series.some((point) => point.value < 0);

    if (hasNegative) {
        return 'Log scale requires positive values. Your net worth includes negative months.';
    }

    if (hasZero) {
        return 'Log scale requires positive values. Your net worth includes zero value months.';
    }

    return null;
}

/**
 * Format currency value for display
 */
export function formatCurrencyValue(
    value: number,
    currencyCode: string,
    options?: {
        minimumFractionDigits?: number;
        maximumFractionDigits?: number;
    },
): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: options?.minimumFractionDigits ?? 0,
        maximumFractionDigits: options?.maximumFractionDigits ?? 0,
    }).format(value / 100);
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
