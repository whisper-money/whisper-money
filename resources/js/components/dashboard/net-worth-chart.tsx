import { AccountName } from '@/components/accounts/account-name';
import {
    type ChartGranularity,
    ChartGranularityToggle,
    ChartSettingsPopover,
    ChartViewToggle,
    MoMChart,
    MoMPercentChart,
} from '@/components/charts';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import { StackedAreaChart } from '@/components/ui/stacked-area-chart';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useChartViews } from '@/hooks/use-chart-views';
import {
    NetWorthEvolutionData,
    OriginalAmount,
} from '@/hooks/use-dashboard-data';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    AccountInfo,
    getAccountSign,
    isLiabilityType,
} from '@/lib/chart-calculations';
import { SharedData } from '@/types';
import { formatDayFromDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { format, subDays } from 'date-fns';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface NetWorthChartProps {
    data: NetWorthEvolutionData;
    loading?: boolean;
    showLegend?: boolean;
}

const DAILY_DAYS = 30;

interface TrendData {
    percentage: number;
    previousAmount: number;
    currentAmount: number;
}

function formatXAxisLabel(
    value: string,
    locale: string = 'en',
    granularity: ChartGranularity = 'monthly',
): string {
    if (granularity === 'daily') {
        return formatDayFromDate(value, locale);
    }

    const [year, month] = value.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const monthName = date.toLocaleString(locale, { month: 'short' });
    const currentYear = new Date().getFullYear();

    if (parseInt(year) === currentYear) {
        return monthName;
    }

    return `${monthName} ${year.slice(-2)}`;
}

function calculateTrend(
    data: Array<Record<string, string | number | OriginalAmount>>,
    accounts: Record<string, AccountInfo>,
    periodsBack: number,
): TrendData | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - periodsBack);

    if (currentIndex === previousIndex) return null;

    const accountIds = Object.keys(accounts);

    const currentTotal = accountIds.reduce((sum, id) => {
        const value = data[currentIndex][id];
        if (typeof value !== 'number') {
            return sum;
        }

        const account = accounts[id];

        return sum + getAccountSign(account.type) * Math.abs(value);
    }, 0);

    const previousTotal = accountIds.reduce((sum, id) => {
        const value = data[previousIndex][id];
        if (typeof value !== 'number') {
            return sum;
        }

        const account = accounts[id];

        return sum + getAccountSign(account.type) * Math.abs(value);
    }, 0);

    if (previousTotal === 0) return null;

    return {
        percentage:
            ((currentTotal - previousTotal) / Math.abs(previousTotal)) * 100,
        previousAmount: previousTotal,
        currentAmount: currentTotal,
    };
}

interface EncryptedLabelProps {
    account: { name: string; name_iv: string | null; encrypted: boolean };
}

function EncryptedLabel({ account }: EncryptedLabelProps) {
    return <AccountName account={account} length={{ min: 5, max: 20 }} />;
}

export function NetWorthChart({
    data: monthlyData,
    loading,
    showLegend = false,
}: NetWorthChartProps) {
    const { props } = usePage<SharedData>();
    const locale = useLocale();
    const isMobile = useIsMobile();
    const { liabilityDotColor } = useChartColors();
    const [granularity, setGranularity] = useState<ChartGranularity>('monthly');
    const [dailyData, setDailyData] = useState<NetWorthEvolutionData | null>(
        null,
    );
    const [isDailyLoading, setIsDailyLoading] = useState(false);
    const includeLoansInNetWorthChart =
        props.includeLoansInNetWorthChart ?? true;
    const includeRealEstateInNetWorthChart =
        props.includeRealEstateInNetWorthChart ?? true;

    const fetchDailyData = useCallback(async () => {
        setIsDailyLoading(true);
        try {
            const now = new Date();
            const to = format(now, 'yyyy-MM-dd');
            const from = format(subDays(now, DAILY_DAYS), 'yyyy-MM-dd');
            const params = new URLSearchParams({ from, to });
            const response = await fetch(
                `/api/dashboard/net-worth-daily-evolution?${params.toString()}`,
            );
            const data: NetWorthEvolutionData = await response.json();

            // Normalize daily data: rename "date" key to "month" so it works
            // uniformly with useChartViews and the rest of the component.
            const normalizedData = data.data.map((point) => {
                const { date, ...rest } = point as Record<string, unknown> & {
                    date: string;
                };
                return { ...rest, month: date };
            }) as Array<Record<string, string | number | OriginalAmount>>;

            setDailyData({
                data: normalizedData,
                accounts: data.accounts,
                currency_code: data.currency_code,
            });
        } catch (error) {
            console.error('Failed to fetch daily net worth data:', error);
        } finally {
            setIsDailyLoading(false);
        }
    }, []);

    useEffect(() => {
        if (granularity === 'daily' && !dailyData) {
            fetchDailyData();
        }
    }, [granularity, dailyData, fetchDailyData]);

    const activeData =
        granularity === 'daily' && dailyData ? dailyData : monthlyData;

    const userCurrency = activeData.currency_code || 'USD';

    const {
        scaledChartData,
        rawChartData,
        dataKeys,
        chartConfig,
        shortTrend,
        longTrend,
        totalAmount,
        accountCurrencies,
        accountsForHook,
        hasLiabilities,
    } = useMemo(() => {
        const accounts = activeData.accounts || {};
        const chartDataArray = activeData.data || [];

        // All accounts included based on the toggles – used for totals & trends.
        const includedAccounts = Object.fromEntries(
            Object.entries(accounts).filter(([, account]) => {
                if (!includeLoansInNetWorthChart && account.type === 'loan') {
                    return false;
                }
                if (
                    !includeRealEstateInNetWorthChart &&
                    account.type === 'real_estate'
                ) {
                    return false;
                }
                return true;
            }),
        );

        // Split into assets (chart segments) and liabilities (affect totals only).
        const assetAccounts = Object.fromEntries(
            Object.entries(includedAccounts).filter(
                ([, account]) => !isLiabilityType(account.type),
            ),
        );
        const liabilityAccountIds = Object.keys(includedAccounts).filter((id) =>
            isLiabilityType(includedAccounts[id].type),
        );
        const hasLiabs = liabilityAccountIds.length > 0;

        // Sort asset accounts by descending average balance so the largest
        // accounts are at the bottom of the stacked chart and smallest on top.
        const chartAccountIds = Object.keys(assetAccounts).sort((a, b) => {
            const valuesA = chartDataArray
                .map((p) => p[a])
                .filter((v): v is number => typeof v === 'number');
            const avgA =
                valuesA.length > 0
                    ? valuesA.reduce((s, v) => s + v, 0) / valuesA.length
                    : 0;

            const valuesB = chartDataArray
                .map((p) => p[b])
                .filter((v): v is number => typeof v === 'number');
            const avgB =
                valuesB.length > 0
                    ? valuesB.reduce((s, v) => s + v, 0) / valuesB.length
                    : 0;

            return avgB - avgA;
        });

        const config: ChartConfig = {};
        const currencies: Record<string, string> = {};
        const hookAccounts: Record<string, AccountInfo> = {};

        // Build config and currencies only for asset accounts (chart segments).
        chartAccountIds.forEach((id) => {
            const account = assetAccounts[id];
            config[id] = {
                label: account ? <EncryptedLabel account={account} /> : id,
            };
            if (account?.currency_code) {
                currencies[id] = account.currency_code;
            }
            if (account) {
                hookAccounts[id] = {
                    id: account.id,
                    type: account.type,
                    currency_code: account.currency_code,
                };
            }
        });

        // Also register liability accounts so the useChartViews MoM/percent
        // series and calculateTrend include them in net worth.
        const allIncludedIds = Object.keys(includedAccounts);
        const allHookAccounts: Record<string, AccountInfo> = {
            ...hookAccounts,
        };
        allIncludedIds.forEach((id) => {
            if (!allHookAccounts[id]) {
                const account = includedAccounts[id];
                if (account) {
                    allHookAccounts[id] = {
                        id: account.id,
                        type: account.type,
                        currency_code: account.currency_code,
                    };
                }
            }
        });

        // Build scaled chart data:
        // - Each asset value is proportionally scaled so total bar height = net worth
        // - Original values stored as `${id}_display` for tooltip display
        // - Liability total and net worth stored as metadata
        const scaled = chartDataArray.map((point) => {
            const newPoint: Record<string, string | number | OriginalAmount> =
                {};

            // Copy non-account fields
            if (point.month !== undefined) newPoint.month = point.month;
            if (point.timestamp !== undefined)
                newPoint.timestamp = point.timestamp;

            // Compute totals for this data point
            let totalAssets = 0;
            let totalLiabilities = 0;

            chartAccountIds.forEach((id) => {
                const value = point[id];
                if (typeof value === 'number') {
                    totalAssets += Math.abs(value);
                }
            });

            liabilityAccountIds.forEach((id) => {
                const value = point[id];
                if (typeof value === 'number') {
                    totalLiabilities += Math.abs(value);
                }
            });

            const netWorth = totalAssets - totalLiabilities;
            const scaleFactor =
                hasLiabs && totalAssets > 0
                    ? Math.max(0, netWorth / totalAssets)
                    : 1;

            // Asset values: scaled for rendering, original for tooltip
            chartAccountIds.forEach((id) => {
                const value = point[id];
                if (typeof value === 'number') {
                    newPoint[id] = value * scaleFactor;
                    if (hasLiabs) {
                        newPoint[`${id}_display`] = value;
                    }
                }

                // Copy _original entries for multi-currency display
                const originalKey = `${id}_original`;
                if (point[originalKey] !== undefined) {
                    newPoint[originalKey] = point[originalKey];
                }
            });

            // Per-liability account data for tooltip (one row per loan)
            if (hasLiabs) {
                const liabilities: Array<{ name: string; amount: number }> = [];
                liabilityAccountIds.forEach((id) => {
                    const value = point[id];
                    if (typeof value === 'number' && Math.abs(value) > 0) {
                        const account = includedAccounts[id];
                        liabilities.push({
                            name: account.name,
                            amount: Math.abs(value),
                        });
                    }
                });
                // Store as JSON string since data points only hold primitives
                newPoint.__liabilities = JSON.stringify(liabilities);
                newPoint.__liabilities_total = totalLiabilities;
                newPoint.__net_worth = netWorth;
            }

            return newPoint;
        });

        // Compute the signed total across ALL included accounts.
        let total = 0;
        if (chartDataArray.length > 0) {
            const lastDataPoint = chartDataArray[chartDataArray.length - 1];
            allIncludedIds.forEach((id) => {
                const value = lastDataPoint[id];
                if (typeof value === 'number') {
                    const account = includedAccounts[id];
                    total += getAccountSign(account.type) * Math.abs(value);
                }
            });
        }

        return {
            scaledChartData: scaled,
            rawChartData: chartDataArray,
            dataKeys: chartAccountIds,
            chartConfig: config,
            shortTrend: calculateTrend(chartDataArray, allHookAccounts, 1),
            longTrend: calculateTrend(
                chartDataArray,
                allHookAccounts,
                chartDataArray.length - 1,
            ),
            totalAmount: total,
            accountCurrencies: currencies,
            accountsForHook: allHookAccounts,
            hasLiabilities: hasLiabs,
        };
    }, [
        activeData,
        includeLoansInNetWorthChart,
        includeRealEstateInNetWorthChart,
    ]);

    const chartViews = useChartViews({
        data: rawChartData as Array<Record<string, string | number>>,
        accounts: accountsForHook,
        initialView: 'stacked',
        hasStackedView: true,
        netWorthOptions: {
            includeLoanAccounts: includeLoansInNetWorthChart,
            includeRealEstateAccounts: includeRealEstateInNetWorthChart,
        },
    });

    const handleIncludeLoansChange = useCallback((includeLoans: boolean) => {
        router.patch(
            '/settings/net-worth-chart-loan-preference',
            {
                include_loans_in_net_worth_chart: includeLoans,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['includeLoansInNetWorthChart'],
            },
        );
    }, []);

    const handleIncludeRealEstateChange = useCallback(
        (includeRealEstate: boolean) => {
            router.patch(
                '/settings/net-worth-chart-real-estate-preference',
                {
                    include_real_estate_in_net_worth_chart: includeRealEstate,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['includeRealEstateInNetWorthChart'],
                },
            );
        },
        [],
    );

    const settingsToggles = useMemo(
        () => [
            {
                id: 'include-real-estate-in-net-worth-chart',
                label: __('Include real estate'),
                description: __(
                    'Include real estate assets in the net worth totals and chart',
                ),
                checked: includeRealEstateInNetWorthChart,
                onChange: handleIncludeRealEstateChange,
            },
        ],
        [includeRealEstateInNetWorthChart, handleIncludeRealEstateChange],
    );

    const valueFormatter = useMemo(() => {
        return (value: number): React.ReactNode => {
            return (
                <AmountDisplay
                    amountInCents={value}
                    currencyCode={userCurrency}
                />
            );
        };
    }, [userCurrency]);

    const shortTrendLabel =
        granularity === 'daily' ? __('today') : __('this month');
    const longTrendLabel =
        granularity === 'daily'
            ? __('for the last 30 days')
            : __('for the last 12 months');

    if (loading || (granularity === 'daily' && isDailyLoading)) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Net Worth Evolution')}</CardTitle>
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

    if (dataKeys.length === 0) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Net Worth Evolution')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        {__('No account data available')}
                    </div>
                </CardContent>
            </Card>
        );
    }

    const xAxisFormatter = (value: string) =>
        formatXAxisLabel(value, locale, granularity);

    return (
        <Card className="group">
            <CardHeader>
                <div className="flex flex-row items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <CardTitle>{__('Net Worth Evolution')}</CardTitle>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <div className="text-foreground">
                                <AmountDisplay
                                    amountInCents={totalAmount}
                                    currencyCode={userCurrency}
                                    variant="large"
                                />
                            </div>
                            <PercentageTrendIndicator
                                trend={shortTrend?.percentage ?? null}
                                label={shortTrendLabel}
                                previousAmount={shortTrend?.previousAmount}
                                currentAmount={shortTrend?.currentAmount}
                                currencyCode={userCurrency}
                            />

                            <PercentageTrendIndicator
                                trend={longTrend?.percentage ?? null}
                                label={longTrendLabel}
                                previousAmount={longTrend?.previousAmount}
                                currentAmount={longTrend?.currentAmount}
                                currencyCode={userCurrency}
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
                                includeLoansLabel={__('Include loans')}
                                includeLoans={includeLoansInNetWorthChart}
                                onIncludeLoansChange={handleIncludeLoansChange}
                                toggles={settingsToggles}
                            />
                        ) : (
                            <>
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
                                <ChartSettingsPopover
                                    granularity={granularity}
                                    onGranularityChange={setGranularity}
                                    currentView={chartViews.currentView}
                                    onViewChange={chartViews.setCurrentView}
                                    availableViews={chartViews.availableViews}
                                    showChartControls={false}
                                    includeLoansLabel={__('Include loans')}
                                    includeLoans={includeLoansInNetWorthChart}
                                    onIncludeLoansChange={
                                        handleIncludeLoansChange
                                    }
                                    toggles={settingsToggles}
                                />
                            </>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="relative min-w-0 overflow-hidden">
                {chartViews.currentView === 'stacked' &&
                    (granularity === 'daily' ? (
                        <StackedAreaChart
                            key={dataKeys.join(',')}
                            data={scaledChartData.slice(1)}
                            dataKeys={dataKeys}
                            config={chartConfig}
                            xAxisKey="month"
                            xAxisFormatter={xAxisFormatter}
                            valueFormatter={valueFormatter}
                            accountCurrencies={accountCurrencies}
                            displayCurrency={userCurrency}
                            className="h-[300px] w-full"
                            showLegend={showLegend}
                            netWorthMode={
                                hasLiabilities
                                    ? {
                                          liabilityTypeLabel: __('Loan'),
                                          liabilityDotColor,
                                      }
                                    : undefined
                            }
                        />
                    ) : (
                        <StackedBarChart
                            key={dataKeys.join(',')}
                            data={scaledChartData.slice(1)}
                            dataKeys={dataKeys}
                            config={chartConfig}
                            xAxisKey="month"
                            xAxisFormatter={xAxisFormatter}
                            valueFormatter={valueFormatter}
                            accountCurrencies={accountCurrencies}
                            displayCurrency={userCurrency}
                            className="h-[300px] w-full"
                            showLegend={showLegend}
                            netWorthMode={
                                hasLiabilities
                                    ? {
                                          liabilityTypeLabel: __('Loan'),
                                          liabilityDotColor,
                                      }
                                    : undefined
                            }
                        />
                    ))}
                {chartViews.currentView === 'mom' && (
                    <MoMChart
                        data={chartViews.deltaSeries}
                        currencyCode={userCurrency}
                        xAxisFormatter={xAxisFormatter}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'mom_percent' && (
                    <MoMPercentChart
                        data={chartViews.momPercentSeries}
                        xAxisFormatter={xAxisFormatter}
                        className="h-[300px] w-full"
                    />
                )}
            </CardContent>
        </Card>
    );
}
