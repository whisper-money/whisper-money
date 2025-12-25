import {
    AccountInfo,
    canUseLogScale,
    ChangeDataPoint,
    computeDeltaSeries,
    computeMoMPercent,
    computeNetWorthSeries,
    computeRolling12mChange,
    computeRolling12mPercent,
    computeWaterfallSeries,
    computeYoYPercent,
    getLogScaleWarning,
    MonthDataPoint,
    PercentDataPoint,
    WaterfallDataPoint,
} from '@/lib/chart-calculations';
import { useCallback, useMemo, useState } from 'react';

export type ChartViewType = 'stacked' | 'line' | 'change' | 'waterfall';
export type ChangeSeriesType = 'mom' | 'yoy' | 'rolling12m' | 'rolling12m_pct';
export type ScaleType = 'linear' | 'log';

export interface UseChartViewsOptions {
    /** Raw monthly data with account balances */
    data: Array<Record<string, string | number>>;
    /** Account information keyed by account ID */
    accounts: Record<string, AccountInfo>;
    /** Initial view type */
    initialView?: ChartViewType;
    /** Whether to show stacked view option (false for single account charts) */
    hasStackedView?: boolean;
}

export interface UseChartViewsReturn {
    // View state
    currentView: ChartViewType;
    setCurrentView: (view: ChartViewType) => void;
    availableViews: ChartViewType[];

    // Scale state (for line chart)
    scaleType: ScaleType;
    setScaleType: (scale: ScaleType) => void;
    canUseLog: boolean;
    logScaleWarning: string | null;

    // Change series state (for change chart)
    changeSeriesType: ChangeSeriesType;
    setChangeSeriesType: (type: ChangeSeriesType) => void;

    // Waterfall state
    waterfallMonthIndex: number;
    setWaterfallMonthIndex: (index: number) => void;

    // Computed series
    netWorthSeries: MonthDataPoint[];
    deltaSeries: ChangeDataPoint[];
    momPercentSeries: PercentDataPoint[];
    yoyPercentSeries: PercentDataPoint[];
    rolling12mChangeSeries: ChangeDataPoint[];
    rolling12mPercentSeries: PercentDataPoint[];
    waterfallSeries: WaterfallDataPoint[];

    // Current change series based on selected type
    currentChangeSeries: PercentDataPoint[] | ChangeDataPoint[];
    changeSeriesKey: 'percent' | 'change';
}

/**
 * Hook for managing chart view state and computed series
 * Shared between net-worth-chart and account-balance-chart
 */
export function useChartViews({
    data,
    accounts,
    initialView = 'stacked',
    hasStackedView = true,
}: UseChartViewsOptions): UseChartViewsReturn {
    // View state
    const [currentView, setCurrentViewState] = useState<ChartViewType>(
        hasStackedView ? initialView : initialView === 'stacked' ? 'line' : initialView,
    );
    const [scaleType, setScaleType] = useState<ScaleType>('linear');
    const [changeSeriesType, setChangeSeriesType] =
        useState<ChangeSeriesType>('mom');
    const [waterfallMonthIndex, setWaterfallMonthIndex] = useState<number>(-1);

    // Available views based on chart type
    const availableViews = useMemo<ChartViewType[]>(() => {
        if (hasStackedView) {
            return ['stacked', 'line', 'change', 'waterfall'];
        }
        return ['line', 'change', 'waterfall'];
    }, [hasStackedView]);

    // Set current view with validation
    const setCurrentView = useCallback(
        (view: ChartViewType) => {
            if (availableViews.includes(view)) {
                setCurrentViewState(view);
            }
        },
        [availableViews],
    );

    // Compute net worth series
    const netWorthSeries = useMemo(() => {
        return computeNetWorthSeries(data, accounts);
    }, [data, accounts]);

    // Log scale checks
    const canUseLog = useMemo(() => {
        return canUseLogScale(netWorthSeries);
    }, [netWorthSeries]);

    const logScaleWarning = useMemo(() => {
        return getLogScaleWarning(netWorthSeries);
    }, [netWorthSeries]);

    // Compute delta series
    const deltaSeries = useMemo(() => {
        return computeDeltaSeries(netWorthSeries);
    }, [netWorthSeries]);

    // Compute MoM percent series
    const momPercentSeries = useMemo(() => {
        return computeMoMPercent(netWorthSeries);
    }, [netWorthSeries]);

    // Compute YoY percent series
    const yoyPercentSeries = useMemo(() => {
        return computeYoYPercent(netWorthSeries);
    }, [netWorthSeries]);

    // Compute rolling 12m change series
    const rolling12mChangeSeries = useMemo(() => {
        return computeRolling12mChange(netWorthSeries);
    }, [netWorthSeries]);

    // Compute rolling 12m percent series
    const rolling12mPercentSeries = useMemo(() => {
        return computeRolling12mPercent(netWorthSeries);
    }, [netWorthSeries]);

    // Compute waterfall series
    const waterfallSeries = useMemo(() => {
        const index =
            waterfallMonthIndex === -1
                ? netWorthSeries.length - 1
                : waterfallMonthIndex;
        return computeWaterfallSeries(netWorthSeries, index);
    }, [netWorthSeries, waterfallMonthIndex]);

    // Get current change series based on selected type
    const currentChangeSeries = useMemo(() => {
        switch (changeSeriesType) {
            case 'mom':
                return momPercentSeries;
            case 'yoy':
                return yoyPercentSeries;
            case 'rolling12m':
                return rolling12mChangeSeries;
            case 'rolling12m_pct':
                return rolling12mPercentSeries;
            default:
                return momPercentSeries;
        }
    }, [
        changeSeriesType,
        momPercentSeries,
        yoyPercentSeries,
        rolling12mChangeSeries,
        rolling12mPercentSeries,
    ]);

    // Determine the key used in the current series (percent or change)
    const changeSeriesKey = useMemo<'percent' | 'change'>(() => {
        return changeSeriesType === 'rolling12m' ? 'change' : 'percent';
    }, [changeSeriesType]);

    return {
        // View state
        currentView,
        setCurrentView,
        availableViews,

        // Scale state
        scaleType,
        setScaleType,
        canUseLog,
        logScaleWarning,

        // Change series state
        changeSeriesType,
        setChangeSeriesType,

        // Waterfall state
        waterfallMonthIndex,
        setWaterfallMonthIndex,

        // Computed series
        netWorthSeries,
        deltaSeries,
        momPercentSeries,
        yoyPercentSeries,
        rolling12mChangeSeries,
        rolling12mPercentSeries,
        waterfallSeries,

        // Current change series
        currentChangeSeries,
        changeSeriesKey,
    };
}

/**
 * Convert single-account balance data to the format expected by useChartViews
 * For use in account-balance-chart
 */
export function convertSingleAccountData(
    balanceData: Array<{ month: string; timestamp?: number; value: number }>,
    accountId: string,
    accountType: string,
    currencyCode: string,
): {
    data: Array<Record<string, string | number>>;
    accounts: Record<string, AccountInfo>;
} {
    const data = balanceData.map((point) => ({
        month: point.month,
        timestamp: point.timestamp ?? 0,
        [accountId]: point.value,
    }));

    const accounts: Record<string, AccountInfo> = {
        [accountId]: {
            id: accountId,
            type: accountType as AccountInfo['type'],
            currency_code: currencyCode,
        },
    };

    return { data, accounts };
}
