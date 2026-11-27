import { shortAccounts, type ShortAccount } from '@/lib/forecast/all-accounts';
import { type ForecastChartPoint } from '@/lib/forecast/chart-series';
import { type ForecastAllData, type ForecastData } from '@/types/forecast';

/**
 * Where a month's figures come from: what really happened (`actual`), what
 * happened so far plus what is still to come (`today`), or the forecast.
 */
export type ChartTooltipKind = 'actual' | 'today' | 'forecast';

export interface ChartTooltipData {
    month: string;
    kind: ChartTooltipKind;
    income: number;
    expenses: number;
    /** Null before the account's balance history starts. */
    close: number | null;
    /** The likely range of the close, low then high; only once it is a forecast. */
    range: [number, number] | null;
    /** The lowest balance of the month, in the single-account view only. */
    min: number | null;
    /** The accounts that run short this month, in the "All accounts" view only. */
    shortAccounts: ShortAccount[];
}

const KINDS = {
    past: 'actual',
    current: 'today',
    future: 'forecast',
} as const;

/**
 * What the chart's tooltip says about a month, or null for a month locked
 * behind Pro, which carries no figure to read.
 */
export function chartTooltipData(
    point: ForecastChartPoint,
    forecast: ForecastData | ForecastAllData,
): ChartTooltipData | null {
    if (point.status === 'locked') {
        return null;
    }

    const all = forecast.scope === 'all';

    return {
        month: point.month,
        kind: KINDS[point.status],
        income: point.income ?? 0,
        expenses: point.expenses ?? 0,
        close: point.close,
        range: point.band,
        min: all ? null : point.min,
        shortAccounts:
            all && point.status !== 'past'
                ? shortAccounts(forecast, point.month)
                : [],
    };
}

/** The gap the phone's tooltip keeps from the screen's edges, in pixels. */
const SCREEN_MARGIN = 8;

/**
 * Where the phone's tooltip starts, from the left of the chart: centred
 * over the tapped bar, then pushed back inside the screen when the bar sits
 * near an edge. All measures are in pixels; `barCenter` and `chartLeft` from
 * the screen's left edge.
 */
export function mobileTooltipLeft({
    barCenter,
    chartLeft,
    tooltipWidth,
    screenWidth,
}: {
    barCenter: number;
    chartLeft: number;
    tooltipWidth: number;
    screenWidth: number;
}): number {
    const widest = screenWidth - SCREEN_MARGIN - tooltipWidth;
    const left = Math.max(
        SCREEN_MARGIN,
        Math.min(barCenter - tooltipWidth / 2, widest),
    );

    return Math.round(left - chartLeft);
}
