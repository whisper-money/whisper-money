import { AccountName } from '@/components/accounts/account-name';
import {
    type ChartCurrencyMode,
    type ChartGranularity,
    ChartCurrencyToggle,
    ChartGranularityToggle,
    ChartSettingsPopover,
    ChartViewToggle,
    MoMChart,
    MoMPercentChart,
} from '@/components/charts';
import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartConfig,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import {
    convertSingleAccountData,
    useChartViews,
} from '@/hooks/use-chart-views';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    Account,
    isRealEstateAccount,
    supportsInvestedAmount,
} from '@/types/account';
import { formatCurrency } from '@/utils/currency';
import { formatDayFromDate, formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { format, subDays, subMonths } from 'date-fns';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    ComposedChart,
    Line,
    ReferenceLine,
    XAxis,
} from 'recharts';

const DAILY_DAYS = 30;

function InvestmentTooltipContent({
    active,
    payload,
    valueFormatter,
}: {
    active?: boolean;
    payload?: Array<{
        dataKey?: string | number;
        name?: string;
        value?: number | string;
        color?: string;
        payload?: Record<string, unknown>;
    }>;
    valueFormatter: (value: number) => string;
}) {
    if (!active || !payload?.length) return null;

    const balanceItem = payload.find((p) => p.dataKey === 'value');
    const investedItem = payload.find((p) => p.dataKey === 'invested_amount');

    const balance =
        typeof balanceItem?.value === 'number' ? balanceItem.value : null;
    const invested =
        typeof investedItem?.value === 'number' ? investedItem.value : null;
    const gain =
        balance !== null && invested !== null ? balance - invested : null;

    return (
        <div className="grid min-w-[8rem] items-start gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="grid gap-1.5">
                {payload.map((item) => (
                    <div
                        key={String(item.dataKey)}
                        className="flex w-full items-center gap-2"
                    >
                        <div
                            className="size-2.5 rounded-xs"
                            style={{ backgroundColor: item.color }}
                        />
                        <div className="flex flex-1 justify-between gap-4">
                            <span className="text-muted-foreground">
                                {item.dataKey === 'value'
                                    ? __('Balance')
                                    : __('Invested')}
                            </span>
                            <span className="font-mono font-medium text-foreground tabular-nums">
                                {typeof item.value === 'number'
                                    ? valueFormatter(item.value)
                                    : item.value}
                            </span>
                        </div>
                    </div>
                ))}
                {gain !== null && (
                    <div className="flex w-full items-center gap-2 border-t border-border/50 pt-1.5">
                        <div className="size-2.5" />
                        <div className="flex flex-1 justify-between gap-4">
                            <span className="text-muted-foreground">
                                {__('Gain/loss')}
                            </span>
                            <span
                                className={`font-mono font-medium whitespace-nowrap tabular-nums ${gain >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                            >
                                {gain >= 0 ? '+' : ''}
                                {valueFormatter(gain)}
                            </span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function RealEstateTooltipContent({
    active,
    payload,
    valueFormatter,
}: {
    active?: boolean;
    payload?: Array<{
        dataKey?: string | number;
        name?: string;
        value?: number | string;
        color?: string;
        payload?: Record<string, unknown>;
    }>;
    valueFormatter: (value: number) => string;
}) {
    const { accountMainLineColor, mortgageLineColor } = useChartColors();

    if (!active || !payload?.length) return null;

    const marketValueItem = payload.find((p) => p.dataKey === 'value');
    const mortgageItem = payload.find((p) => p.dataKey === 'mortgage_balance');

    const marketValue =
        typeof marketValueItem?.value === 'number'
            ? marketValueItem.value
            : null;
    const mortgageOwed =
        typeof mortgageItem?.value === 'number' ? mortgageItem.value : null;
    const equity =
        marketValue !== null && mortgageOwed !== null
            ? marketValue - mortgageOwed
            : null;

    return (
        <div className="grid min-w-[8rem] items-start gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="grid gap-1.5">
                {marketValue !== null && (
                    <div className="flex w-full items-center gap-2">
                        <div
                            className="size-2.5 rounded-xs"
                            style={{
                                backgroundColor:
                                    marketValueItem?.color ??
                                    accountMainLineColor,
                            }}
                        />
                        <div className="flex flex-1 justify-between gap-4">
                            <span className="text-muted-foreground">
                                {__('Market value')}
                            </span>
                            <span className="font-mono font-medium text-foreground tabular-nums">
                                {valueFormatter(marketValue)}
                            </span>
                        </div>
                    </div>
                )}
                {mortgageOwed !== null && (
                    <div className="flex w-full items-center gap-2">
                        <div
                            className="size-2.5 rounded-xs"
                            style={{
                                backgroundColor:
                                    mortgageItem?.color ?? mortgageLineColor,
                            }}
                        />
                        <div className="flex flex-1 justify-between gap-4">
                            <span className="text-muted-foreground">
                                {__('Mortgage owed')}
                            </span>
                            <span className="font-mono font-medium text-foreground tabular-nums">
                                {valueFormatter(mortgageOwed)}
                            </span>
                        </div>
                    </div>
                )}
                {equity !== null && (
                    <div className="flex w-full items-center gap-2 border-t border-border/50 pt-1.5">
                        <div className="size-2.5" />
                        <div className="flex flex-1 justify-between gap-4">
                            <span className="text-muted-foreground">
                                {__('Equity')}
                            </span>
                            <span
                                className={`font-mono font-medium whitespace-nowrap tabular-nums ${equity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                            >
                                {valueFormatter(equity)}
                            </span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export interface BalanceDataPoint {
    month: string;
    timestamp: number;
    value: number;
    invested_amount?: number | null;
    mortgage_balance?: number | null;
    projected?: boolean;
    projected_value?: number;
    projected_mortgage_balance?: number;
    display_value?: number;
    display_invested_amount?: number | null;
    display_mortgage_balance?: number;
}

interface AccountBalanceData {
    data: BalanceDataPoint[];
    account: {
        id: string;
        name: string;
        name_iv: string;
        type: string;
        currency_code: string;
    };
    display_currency_code?: string;
}

interface DailyBalanceDataPoint {
    date: string;
    timestamp: number;
    value: number;
    invested_amount?: number | null;
    mortgage_balance?: number | null;
    display_value?: number;
    display_invested_amount?: number | null;
    display_mortgage_balance?: number;
}

interface AccountDailyBalanceData {
    data: DailyBalanceDataPoint[];
    account: {
        id: string;
        name: string;
        name_iv: string;
        type: string;
        currency_code: string;
    };
    display_currency_code?: string;
}

export interface ChartComputedData {
    chartData: BalanceDataPoint[];
    currentBalance: number;
    currentMortgageBalance: number | null;
    currencyCode: string;
    hasMortgageData: boolean;
    shortTrend: ReturnType<typeof calculateTrend>;
    longTrend: ReturnType<typeof calculateTrend>;
}

interface AccountBalanceChartProps {
    account: Account;
    loading?: boolean;
    refreshKey?: number;
    onBalanceClick?: () => void;
    onDataLoaded?: (data: ChartComputedData) => void;
}

function createXAxisFormatter(locale: string, granularity: ChartGranularity) {
    if (granularity === 'daily') {
        return function formatXAxisLabel(value: string): string {
            return formatDayFromDate(value, locale);
        };
    }
    return function formatXAxisLabel(value: string): string {
        return formatMonthFromYearMonth(value, locale);
    };
}

function formatChartCurrency(
    value: number,
    currencyCode: string,
    locale: string,
): string {
    return formatCurrency(value, currencyCode, locale);
}

function calculateTrend(
    data: Array<{ value: number }>,
    periodsBack: number,
): { percentage: number; previousValue: number; currentValue: number } | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - periodsBack);

    if (currentIndex === previousIndex) return null;

    const currentValue = data[currentIndex].value;
    const previousValue = data[previousIndex].value;

    if (previousValue === 0) return null;

    return {
        percentage:
            ((currentValue - previousValue) / Math.abs(previousValue)) * 100,
        previousValue,
        currentValue,
    };
}

/**
 * Normalize daily data points to use "month" key so they work with
 * convertSingleAccountData and useChartViews without changes.
 */
function normalizeDailyData(data: DailyBalanceDataPoint[]): BalanceDataPoint[] {
    return data.map((point) => ({
        month: point.date,
        timestamp: point.timestamp,
        value: point.value,
        invested_amount: point.invested_amount,
        mortgage_balance: point.mortgage_balance,
        display_value: point.display_value,
        display_invested_amount: point.display_invested_amount,
        display_mortgage_balance: point.display_mortgage_balance,
    }));
}

export function AccountBalanceChart({
    account,
    loading: initialLoading,
    refreshKey,
    onBalanceClick,
    onDataLoaded,
}: AccountBalanceChartProps) {
    const locale = useLocale();
    const isMobile = useIsMobile();
    const isLoan = account.type === 'loan';
    const isRealEstate = isRealEstateAccount(account);
    const [granularity, setGranularity] = useState<ChartGranularity>('monthly');
    const [currencyMode, setCurrencyMode] = useState<ChartCurrencyMode>('user');
    const [balanceData, setBalanceData] = useState<AccountBalanceData | null>(
        null,
    );
    const [isLoading, setIsLoading] = useState(true);

    const fetchBalanceData = useCallback(
        async (currentGranularity: ChartGranularity) => {
            setIsLoading(true);
            try {
                const now = new Date();
                const to = format(now, 'yyyy-MM-dd');

                if (currentGranularity === 'daily') {
                    // Fetch DAILY_DAYS + 1 days (extra day for DoD baseline)
                    const from = format(subDays(now, DAILY_DAYS), 'yyyy-MM-dd');
                    const params = new URLSearchParams({ from, to });
                    const response = await fetch(
                        `/api/dashboard/account/${account.id}/daily-balance-evolution?${params.toString()}`,
                    );
                    const data: AccountDailyBalanceData = await response.json();
                    // Normalize daily data so the rest of the component works uniformly
                    setBalanceData({
                        data: normalizeDailyData(data.data),
                        account: data.account,
                        display_currency_code: data.display_currency_code,
                    });
                } else {
                    const from = format(subMonths(now, 12), 'yyyy-MM-dd');
                    const params = new URLSearchParams({ from, to });
                    const response = await fetch(
                        `/api/dashboard/account/${account.id}/balance-evolution?${params.toString()}`,
                    );
                    const data = await response.json();
                    setBalanceData(data);
                }
            } catch (error) {
                console.error('Failed to fetch balance data:', error);
            } finally {
                setIsLoading(false);
            }
        },
        [account.id],
    );

    useEffect(() => {
        fetchBalanceData(granularity);
    }, [fetchBalanceData, granularity, refreshKey]);

    const showInvestmentBenefits = supportsInvestedAmount(account);

    const {
        chartData,
        currentBalance,
        currentInvestedAmount,
        currentMortgageBalance,
        hasMortgageData,
        hasProjectedData,
        shortTrend,
        longTrend,
    } = useMemo(() => {
        if (!balanceData?.data?.length) {
            return {
                chartData: [],
                currentBalance: 0,
                currentInvestedAmount: null as number | null,
                currentMortgageBalance: null as number | null,
                hasMortgageData: false,
                hasProjectedData: false,
                shortTrend: null,
                longTrend: null,
            };
        }

        const data = balanceData.data;

        // For trend calculations, use only non-projected data points
        const historicalData = data.filter((d) => !d.projected);
        const current = historicalData[historicalData.length - 1]?.value ?? 0;

        // Find the most recent non-null invested_amount
        let invested: number | null = null;
        for (let i = data.length - 1; i >= 0; i--) {
            if (
                data[i].invested_amount !== null &&
                data[i].invested_amount !== undefined
            ) {
                invested = data[i].invested_amount!;
                break;
            }
        }

        // Check if any data point has mortgage data
        const hasMortgage = data.some(
            (d) =>
                d.mortgage_balance !== null && d.mortgage_balance !== undefined,
        );

        // Find the most recent mortgage balance
        let mortgage: number | null = null;
        if (hasMortgage) {
            for (let i = data.length - 1; i >= 0; i--) {
                if (
                    data[i].mortgage_balance !== null &&
                    data[i].mortgage_balance !== undefined
                ) {
                    mortgage = data[i].mortgage_balance!;
                    break;
                }
            }
        }

        const hasProjection = data.some((d) => d.projected);

        // Add projected_value / projected_mortgage_balance fields for chart
        // rendering:
        // - Historical points: value/mortgage_balance only
        // - Last historical point: also gets projected_* to connect the lines
        // - Projected points: both value/mortgage_balance and projected_*
        let augmentedData = data;
        if (hasProjection) {
            const lastHistoricalIndex = data.findIndex((d) => d.projected) - 1;
            augmentedData = data.map((d, i) => ({
                ...d,
                projected_value:
                    d.projected || i === lastHistoricalIndex
                        ? d.value
                        : undefined,
                projected_mortgage_balance:
                    (d.projected || i === lastHistoricalIndex) &&
                    d.mortgage_balance !== null &&
                    d.mortgage_balance !== undefined
                        ? d.mortgage_balance
                        : undefined,
            }));
        }

        return {
            chartData: augmentedData,
            currentBalance: current,
            currentInvestedAmount: invested,
            currentMortgageBalance: mortgage,
            hasMortgageData: hasMortgage,
            hasProjectedData: hasProjection,
            shortTrend: calculateTrend(historicalData, 1),
            longTrend: calculateTrend(
                historicalData,
                historicalData.length - 1,
            ),
        };
    }, [balanceData]);

    // Determine if currency toggle is available
    const displayCurrencyCode = balanceData?.display_currency_code ?? null;
    const hasCurrencyToggle = displayCurrencyCode !== null;

    // When in user-currency mode, swap display_* values into the primary fields
    const activeCurrencyCode =
        currencyMode === 'user' && displayCurrencyCode
            ? displayCurrencyCode
            : account.currency_code;

    const activeChartData = useMemo(() => {
        if (currencyMode === 'user' && hasCurrencyToggle) {
            // User currency mode: swap balance to display_value (account→user),
            // but invested_amount is already in user currency — keep as-is
            return chartData.map((point) => ({
                ...point,
                value: point.display_value ?? point.value,
                mortgage_balance:
                    point.display_mortgage_balance !== undefined
                        ? point.display_mortgage_balance
                        : point.mortgage_balance,
                projected_value:
                    'projected_value' in point &&
                    point.display_value !== undefined
                        ? point.display_value
                        : point.projected_value,
                projected_mortgage_balance:
                    'projected_mortgage_balance' in point &&
                    point.display_mortgage_balance !== undefined
                        ? point.display_mortgage_balance
                        : point.projected_mortgage_balance,
            }));
        }

        if (currencyMode === 'account' && hasCurrencyToggle) {
            // Account currency mode: balance is already in account currency,
            // but invested_amount is in user currency — swap to display_invested_amount (user→account)
            return chartData.map((point) => ({
                ...point,
                invested_amount:
                    point.display_invested_amount !== undefined
                        ? point.display_invested_amount
                        : point.invested_amount,
            }));
        }

        return chartData;
    }, [chartData, currencyMode, hasCurrencyToggle]);

    const activeCurrentBalance = useMemo(() => {
        if (currencyMode !== 'user' || !hasCurrencyToggle) {
            return currentBalance;
        }
        const historicalData = activeChartData.filter((d) => !d.projected);
        return historicalData[historicalData.length - 1]?.value ?? 0;
    }, [activeChartData, currentBalance, currencyMode, hasCurrencyToggle]);

    const activeCurrentMortgageBalance = useMemo(() => {
        if (currencyMode !== 'user' || !hasCurrencyToggle) {
            return currentMortgageBalance;
        }
        if (!hasMortgageData) return null;
        for (let i = activeChartData.length - 1; i >= 0; i--) {
            const mb = activeChartData[i].mortgage_balance;
            if (mb !== null && mb !== undefined) return mb;
        }
        return null;
    }, [
        activeChartData,
        currentMortgageBalance,
        currencyMode,
        hasCurrencyToggle,
        hasMortgageData,
    ]);

    const activeCurrentInvestedAmount = useMemo(() => {
        if (!hasCurrencyToggle) {
            return currentInvestedAmount;
        }
        // In both currency modes, activeChartData has the correctly-swapped invested_amount
        for (let i = activeChartData.length - 1; i >= 0; i--) {
            const ia = activeChartData[i].invested_amount;
            if (ia !== null && ia !== undefined) return ia;
        }
        return null;
    }, [activeChartData, currentInvestedAmount, hasCurrencyToggle]);

    const activeShortTrend = useMemo(() => {
        if (currencyMode !== 'user' || !hasCurrencyToggle) return shortTrend;
        const historicalData = activeChartData.filter((d) => !d.projected);
        return calculateTrend(historicalData, 1);
    }, [activeChartData, currencyMode, hasCurrencyToggle, shortTrend]);

    const activeLongTrend = useMemo(() => {
        if (currencyMode !== 'user' || !hasCurrencyToggle) return longTrend;
        const historicalData = activeChartData.filter((d) => !d.projected);
        return calculateTrend(historicalData, historicalData.length - 1);
    }, [activeChartData, currencyMode, hasCurrencyToggle, longTrend]);

    useEffect(() => {
        if (onDataLoaded && activeChartData.length > 0) {
            onDataLoaded({
                chartData: activeChartData,
                currentBalance: activeCurrentBalance,
                currentMortgageBalance: activeCurrentMortgageBalance,
                currencyCode: activeCurrencyCode,
                hasMortgageData,
                shortTrend: activeShortTrend,
                longTrend: activeLongTrend,
            });
        }
    }, [
        activeChartData,
        activeCurrentBalance,
        activeCurrentMortgageBalance,
        activeCurrencyCode,
        hasMortgageData,
        activeShortTrend,
        activeLongTrend,
        onDataLoaded,
    ]);

    // Convert data for useChartViews hook
    const { data: hookData, accounts: hookAccounts } = useMemo(() => {
        return convertSingleAccountData(
            activeChartData,
            account.id,
            account.type,
            activeCurrencyCode,
        );
    }, [activeChartData, account.id, account.type, activeCurrencyCode]);

    const chartViews = useChartViews({
        data: hookData,
        accounts: hookAccounts,
        initialView: 'stacked',
        hasStackedView: true,
    });

    const { accountMainLineColor, mortgageLineColor } = useChartColors();

    const chartConfig: ChartConfig = {
        value: {
            label: (
                <AccountName account={account} length={{ min: 5, max: 20 }} />
            ),

            color: accountMainLineColor,
        },
        ...(showInvestmentBenefits
            ? {
                  invested_amount: {
                      label: __('Invested'),
                      color: 'var(--color-chart-4)',
                  },
              }
            : {}),
        ...(hasMortgageData
            ? {
                  mortgage_balance: {
                      label: __('Mortgage owed'),
                      color: mortgageLineColor,
                  },
              }
            : {}),
        ...(hasProjectedData
            ? {
                  projected_value: {
                      label: __('Projected'),
                      color: hasMortgageData
                          ? accountMainLineColor
                          : 'var(--color-chart-2)',
                  },
                  ...(hasMortgageData
                      ? {
                            projected_mortgage_balance: {
                                label: __('Projected mortgage'),
                                color: mortgageLineColor,
                            },
                        }
                      : {}),
              }
            : {}),
    };

    const formatXAxisLabel = useMemo(
        () => createXAxisFormatter(locale, granularity),
        [locale, granularity],
    );

    const todayMarker = useMemo(() => {
        const now = new Date();
        return granularity === 'daily'
            ? format(now, 'yyyy-MM-dd')
            : format(now, 'yyyy-MM');
    }, [granularity]);

    const valueFormatter = (value: number): string => {
        return formatChartCurrency(value, activeCurrencyCode, locale);
    };

    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const minBarWidth = granularity === 'daily' ? 20 : 50;
    const minChartWidth = activeChartData.length * minBarWidth;

    useEffect(() => {
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollLeft =
                scrollContainerRef.current.scrollWidth;
        }
    }, [activeChartData]);

    const shortTrendLabel =
        granularity === 'daily' ? __('today') : __('this month');
    const longTrendLabel =
        granularity === 'daily'
            ? __('for the last 30 days')
            : __('for the last 12 months');

    const chartTitle = isRealEstate
        ? __('Market value evolution')
        : isLoan
          ? __('Owed amount evolution')
          : __('Balance evolution');
    const emptyMessage = isRealEstate
        ? __('No market value data available')
        : isLoan
          ? __('No owed amount data available')
          : __('No balance data available');
    const currentEquity =
        hasMortgageData && activeCurrentMortgageBalance !== null
            ? activeCurrentBalance - activeCurrentMortgageBalance
            : null;

    if (initialLoading || isLoading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{chartTitle}</CardTitle>
                    <CardDescription>
                        <div className="h-4 w-48 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-[300px] w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    if (chartData.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{chartTitle}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        {emptyMessage}
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="group">
            <CardHeader>
                <div className="flex flex-row items-start justify-between">
                    <div className="flex flex-col gap-1 sm:gap-2">
                        <CardTitle>{chartTitle}</CardTitle>
                        <button
                            type="button"
                            onClick={onBalanceClick}
                            className="-ml-3 cursor-pointer rounded-md px-2 py-1 text-left text-4xl font-semibold tabular-nums transition-colors hover:bg-muted"
                        >
                            <AmountDisplay
                                amountInCents={activeCurrentBalance}
                                currencyCode={activeCurrencyCode}
                            />
                        </button>
                        {currentEquity !== null && (
                            <div className="flex items-baseline gap-2">
                                <span className="text-sm text-muted-foreground">
                                    {__('Equity')}
                                </span>
                                <span
                                    className={`text-lg font-semibold tabular-nums ${currentEquity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                                >
                                    <AmountDisplay
                                        amountInCents={currentEquity}
                                        currencyCode={activeCurrencyCode}
                                    />
                                </span>
                            </div>
                        )}
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <PercentageTrendIndicator
                                trend={activeShortTrend?.percentage ?? null}
                                label={shortTrendLabel}
                                previousAmount={activeShortTrend?.previousValue}
                                currentAmount={activeShortTrend?.currentValue}
                                currencyCode={activeCurrencyCode}
                            />

                            <PercentageTrendIndicator
                                trend={activeLongTrend?.percentage ?? null}
                                label={longTrendLabel}
                                previousAmount={activeLongTrend?.previousValue}
                                currentAmount={activeLongTrend?.currentValue}
                                currencyCode={activeCurrencyCode}
                            />
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        {isMobile ? (
                            <ChartSettingsPopover
                                granularity={granularity}
                                onGranularityChange={setGranularity}
                                currentView={chartViews.currentView}
                                onViewChange={chartViews.setCurrentView}
                                availableViews={chartViews.availableViews}
                                currencyToggle={
                                    hasCurrencyToggle
                                        ? {
                                              value: currencyMode,
                                              onValueChange: setCurrencyMode,
                                              accountCurrencyCode:
                                                  account.currency_code,
                                              userCurrencyCode:
                                                  displayCurrencyCode!,
                                          }
                                        : undefined
                                }
                            />
                        ) : (
                            <>
                                {hasCurrencyToggle && (
                                    <ChartCurrencyToggle
                                        value={currencyMode}
                                        onValueChange={setCurrencyMode}
                                        accountCurrencyCode={
                                            account.currency_code
                                        }
                                        userCurrencyCode={displayCurrencyCode!}
                                    />
                                )}
                                <ChartGranularityToggle
                                    value={granularity}
                                    onValueChange={setGranularity}
                                />
                                <ChartViewToggle
                                    value={chartViews.currentView}
                                    onValueChange={chartViews.setCurrentView}
                                    availableViews={chartViews.availableViews}
                                    granularity={granularity}
                                />
                            </>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="relative">
                {chartViews.currentView === 'stacked' && (
                    <div
                        ref={scrollContainerRef}
                        className="h-[300px] w-full overflow-x-auto"
                    >
                        <ChartContainer
                            config={chartConfig}
                            className="h-full w-full"
                            style={{ minWidth: `${minChartWidth}px` }}
                        >
                            {hasMortgageData ? (
                                <ComposedChart
                                    accessibilityLayer
                                    data={activeChartData.slice(1)}
                                >
                                    <defs>
                                        <linearGradient
                                            id="fillBalance"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="5%"
                                                stopColor={accountMainLineColor}
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="95%"
                                                stopColor={accountMainLineColor}
                                                stopOpacity={0.05}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <RealEstateTooltipContent
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Area
                                        dataKey="value"
                                        type="monotone"
                                        fill="url(#fillBalance)"
                                        stroke={accountMainLineColor}
                                        strokeWidth={2}
                                        dot={false}
                                        activeDot={{ r: 5 }}
                                        fillOpacity={1}
                                    />
                                    <Line
                                        dataKey="mortgage_balance"
                                        type="monotone"
                                        stroke={mortgageLineColor}
                                        strokeWidth={1.5}
                                        strokeDasharray="4 3"
                                        dot={false}
                                        activeDot={{ r: 4 }}
                                        connectNulls
                                    />
                                    {hasProjectedData && (
                                        <>
                                            <Line
                                                dataKey="projected_value"
                                                type="monotone"
                                                stroke={accountMainLineColor}
                                                strokeWidth={2}
                                                strokeDasharray="6 4"
                                                dot={false}
                                                activeDot={{ r: 4 }}
                                                connectNulls
                                            />
                                            <Line
                                                dataKey="projected_mortgage_balance"
                                                type="monotone"
                                                stroke={mortgageLineColor}
                                                strokeWidth={1.5}
                                                strokeDasharray="6 4"
                                                dot={false}
                                                activeDot={{ r: 4 }}
                                                connectNulls
                                            />
                                            <ReferenceLine
                                                x={todayMarker}
                                                stroke="var(--color-foreground)"
                                                strokeWidth={1}
                                                strokeDasharray="4 4"
                                                label={{
                                                    value: __('Today'),
                                                    position: 'top',
                                                    fontSize: 12,
                                                    fill: 'var(--color-muted-foreground)',
                                                }}
                                            />
                                        </>
                                    )}
                                </ComposedChart>
                            ) : granularity === 'daily' ? (
                                <AreaChart
                                    accessibilityLayer
                                    data={activeChartData.slice(1)}
                                >
                                    <defs>
                                        <linearGradient
                                            id="fillBalance"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="5%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="95%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.05}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            showInvestmentBenefits ? (
                                                <InvestmentTooltipContent
                                                    valueFormatter={
                                                        valueFormatter
                                                    }
                                                />
                                            ) : (
                                                <ChartTooltipContent
                                                    hideLabel
                                                    valueFormatter={
                                                        valueFormatter
                                                    }
                                                />
                                            )
                                        }
                                    />
                                    <Area
                                        dataKey="value"
                                        type="monotone"
                                        fill="url(#fillBalance)"
                                        stroke="var(--color-chart-2)"
                                        strokeWidth={2}
                                        dot={false}
                                        activeDot={{ r: 5 }}
                                        fillOpacity={1}
                                    />
                                    {showInvestmentBenefits &&
                                        activeCurrentInvestedAmount !==
                                            null && (
                                            <Line
                                                dataKey="invested_amount"
                                                type="monotone"
                                                stroke="var(--color-chart-6)"
                                                strokeWidth={1.5}
                                                strokeDasharray="2 2"
                                                dot={false}
                                                activeDot={{ r: 4 }}
                                                connectNulls
                                            />
                                        )}
                                </AreaChart>
                            ) : showInvestmentBenefits &&
                              activeCurrentInvestedAmount !== null ? (
                                <ComposedChart
                                    accessibilityLayer
                                    data={activeChartData.slice(1)}
                                >
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <InvestmentTooltipContent
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Bar
                                        dataKey="value"
                                        fill="var(--color-chart-2)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                    <Line
                                        dataKey="invested_amount"
                                        type="monotone"
                                        stroke="var(--color-chart-6)"
                                        strokeWidth={1.5}
                                        strokeDasharray="2 2"
                                        dot={false}
                                        activeDot={{ r: 4 }}
                                        connectNulls
                                    />
                                </ComposedChart>
                            ) : hasProjectedData ? (
                                <ComposedChart
                                    accessibilityLayer
                                    data={activeChartData.slice(1)}
                                >
                                    <defs>
                                        <linearGradient
                                            id="fillBalance"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="5%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="95%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.05}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                hideLabel
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Area
                                        dataKey="value"
                                        type="monotone"
                                        fill="url(#fillBalance)"
                                        stroke="var(--color-chart-2)"
                                        strokeWidth={2}
                                        dot={false}
                                        activeDot={{ r: 5 }}
                                        fillOpacity={1}
                                    />
                                    <Line
                                        dataKey="projected_value"
                                        type="monotone"
                                        stroke="var(--color-chart-2)"
                                        strokeWidth={2}
                                        strokeDasharray="6 4"
                                        dot={false}
                                        activeDot={{ r: 4 }}
                                        connectNulls
                                    />
                                    <ReferenceLine
                                        x={todayMarker}
                                        stroke="var(--color-foreground)"
                                        strokeWidth={1}
                                        strokeDasharray="4 4"
                                        label={{
                                            value: __('Today'),
                                            position: 'top',
                                            fontSize: 12,
                                            fill: 'var(--color-muted-foreground)',
                                        }}
                                    />
                                </ComposedChart>
                            ) : (
                                <BarChart
                                    accessibilityLayer
                                    data={activeChartData.slice(1)}
                                >
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                hideLabel
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Bar
                                        dataKey="value"
                                        fill="var(--color-chart-2)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            )}
                        </ChartContainer>
                    </div>
                )}
                {chartViews.currentView === 'mom' && (
                    <MoMChart
                        data={chartViews.deltaSeries}
                        currencyCode={activeCurrencyCode}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'mom_percent' && (
                    <MoMPercentChart
                        data={chartViews.momPercentSeries}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
            </CardContent>
        </Card>
    );
}
