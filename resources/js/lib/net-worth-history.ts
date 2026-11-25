import {
    type AccountInfo,
    netWorthContribution,
} from '@/lib/chart-calculations';

/**
 * One period of the net worth evolution: its `month` (or day), and each
 * account's balance keyed by account id. A balance is null for a period
 * before anything is known about the account (its first balance or movement).
 */
export type NetWorthHistoryPoint = Record<string, unknown>;

export interface NetWorthTrend {
    percentage: number;
    previousAmount: number;
    currentAmount: number;
}

/**
 * The index of the first period where any of the accounts has a figure, or -1
 * when none has one. Every period before it is "no data": nothing to draw and
 * nothing to compare against.
 */
export function firstKnownIndex(
    data: NetWorthHistoryPoint[],
    accountIds: string[],
): number {
    return data.findIndex((point) =>
        accountIds.some((id) => typeof point[id] === 'number'),
    );
}

function netWorthAt(
    point: NetWorthHistoryPoint,
    accounts: Record<string, AccountInfo>,
): number {
    return Object.values(accounts).reduce((sum, account) => {
        const value = point[account.id];

        return typeof value === 'number'
            ? sum + netWorthContribution(account.type, value)
            : sum;
    }, 0);
}

/**
 * The change in net worth from `periodsBack` periods ago to the last period.
 * It never reaches back past the first period with data: when the history is
 * shorter than that, it is measured from the first known period instead, and
 * when the period it compares with is unknown there is no trend at all.
 */
export function calculateNetWorthTrend(
    data: NetWorthHistoryPoint[],
    accounts: Record<string, AccountInfo>,
    periodsBack: number,
): NetWorthTrend | null {
    const firstKnown = firstKnownIndex(data, Object.keys(accounts));

    if (firstKnown === -1) {
        return null;
    }

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(firstKnown, currentIndex - periodsBack);

    if (previousIndex >= currentIndex) {
        return null;
    }

    const previousAmount = netWorthAt(data[previousIndex], accounts);
    const currentAmount = netWorthAt(data[currentIndex], accounts);

    if (previousAmount === 0) {
        return null;
    }

    return {
        percentage:
            ((currentAmount - previousAmount) / Math.abs(previousAmount)) * 100,
        previousAmount,
        currentAmount,
    };
}

export interface NetWorthHistorySpan {
    /**
     * The first period the stacked chart draws. Period 0 is only there to
     * compare period 1 with, so it is 1, or the first period with data when
     * that comes later.
     */
    chartStart: number;
    /**
     * The first period the month-over-month series are computed from: the
     * first with data, so the change into it is not read as growth from
     * nothing.
     */
    seriesStart: number;
    /** The periods in the chart's range before the first one with data. */
    unknownPeriods: string[];
    /**
     * The first period with data when the history is shorter than the range,
     * or null when it covers the whole range (or nothing is known).
     */
    historyStart: string | null;
}

/**
 * Where the known history starts in a chart's range of periods, keyed by
 * `month` (a month, or a day in the daily view).
 */
export function netWorthHistorySpan(
    data: NetWorthHistoryPoint[],
    accountIds: string[],
): NetWorthHistorySpan {
    const firstKnown = firstKnownIndex(data, accountIds);
    const chartStart = Math.max(1, firstKnown);

    return {
        chartStart,
        seriesStart: Math.max(0, firstKnown),
        unknownPeriods: data
            .slice(1, chartStart)
            .map((point) => String(point.month)),
        historyStart: firstKnown > 0 ? String(data[firstKnown].month) : null,
    };
}
