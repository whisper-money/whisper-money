import { show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountName } from '@/components/accounts/account-name';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { BankLogo } from '@/components/bank-logo';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { supportsInvestedAmount } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';
import { AccountTypeIcon } from './account-type-icon';
import { AmountTrendIndicator } from './amount-trend-indicator';

interface LinkedLoanMetrics {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    history: Array<{
        date: string;
        value: number;
    }>;
    loanAccount?: {
        name: string;
        bank: { name: string; logo: string | null } | null;
    };
}

interface AccountBalanceCardProps {
    account: AccountWithMetrics;
    loading?: boolean;
    onBalanceUpdated?: () => void;
    linkedLoanMetrics?: LinkedLoanMetrics;
    displayCurrencyCode?: string;
}

export function AccountBalanceCard({
    account,
    loading,
    onBalanceUpdated,
    linkedLoanMetrics,
    displayCurrencyCode,
}: AccountBalanceCardProps) {
    const currencyCode = displayCurrencyCode ?? account.currency_code;
    const { accountMainLineColor, accountGainLineColor, mortgageLineColor } =
        useChartColors();
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);
    const [tooltipHidden, setTooltipHidden] = useState(false);
    const chartWrapperRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        // Only apply tap-to-dismiss behavior on touch devices. On devices
        // with hover support (desktop), the default hover behavior is fine.
        if (
            typeof window === 'undefined' ||
            typeof window.matchMedia !== 'function'
        ) {
            return;
        }

        const hoverQuery = window.matchMedia('(hover: hover)');
        if (hoverQuery.matches) return;

        const handlePointerDown = (event: PointerEvent) => {
            const wrapper = chartWrapperRef.current;
            if (!wrapper) return;
            const insideChart = wrapper.contains(event.target as Node);

            if (insideChart) {
                setTooltipHidden(false);
                return;
            }

            setTooltipHidden(true);
        };

        document.addEventListener('pointerdown', handlePointerDown);
        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
        };
    }, []);

    const hasMortgage = !!linkedLoanMetrics;

    // Compute equity data when this is a real estate account with a linked mortgage
    const equityData = useMemo(() => {
        if (!hasMortgage) return null;

        const marketValue = account.currentBalance;
        const mortgageOwed = linkedLoanMetrics.currentBalance;
        const equity = marketValue - mortgageOwed;

        const prevMarketValue = account.previousBalance;
        const prevMortgageOwed = linkedLoanMetrics.previousBalance;
        const prevEquity = prevMarketValue - prevMortgageOwed;
        const equityDiff = equity - prevEquity;

        // Build dual-line sparkline: market value (solid) + mortgage owed (dashed)
        const marketMap = new Map(
            account.history.map((h) => [h.date, h.value]),
        );
        const mortgageMap = new Map(
            linkedLoanMetrics.history.map((h) => [h.date, h.value]),
        );
        const allDates = [
            ...new Set([...marketMap.keys(), ...mortgageMap.keys()]),
        ].sort();

        let lastMarket = 0;
        let lastMortgage = 0;
        const dualHistory = allDates.map((date) => {
            const mv = marketMap.get(date) ?? lastMarket;
            const mo = mortgageMap.get(date) ?? lastMortgage;
            lastMarket = mv;
            lastMortgage = mo;
            return { date, value: mv, mortgageOwed: mo };
        });

        return {
            equity,
            prevEquity,
            equityDiff,
            dualHistory,
        };
    }, [hasMortgage, account, linkedLoanMetrics]);
    if (loading) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">
                        {__('Loading...')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-20 w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const displayBalance = equityData
        ? equityData.equity
        : account.currentBalance;
    const displayDiff = equityData ? equityData.equityDiff : account.diff;
    const displayPreviousBalance = equityData
        ? equityData.prevEquity
        : account.previousBalance;
    const isPositive = displayDiff >= 0;
    const isConnected = !!account.banking_connection_id;

    const sparklineData = equityData ? equityData.dualHistory : account.history;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <div className="flex flex-col gap-0.5">
                    <CardTitle className="text-sm font-medium">
                        <Link
                            href={show.url(account.id)}
                            className="-my-1 -ml-1.5 flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                        >
                            <BankLogo
                                src={account.bank?.logo ?? null}
                                name={account.bank?.name}
                                className="mr-2 inline-block size-5"
                            />

                            <AccountName
                                account={account}
                                length={{ min: 5, max: 15 }}
                            />
                        </Link>
                    </CardTitle>
                    {hasMortgage && linkedLoanMetrics.loanAccount && (
                        <span className="flex items-center gap-1 pt-0.5 text-xs text-muted-foreground">
                            {__('Mortgage at')}{' '}
                            {linkedLoanMetrics.loanAccount.bank && (
                                <BankLogo
                                    src={
                                        linkedLoanMetrics.loanAccount.bank.logo
                                    }
                                    name={
                                        linkedLoanMetrics.loanAccount.bank.name
                                    }
                                    className="size-3.5 shrink-0"
                                    fallback="letter"
                                />
                            )}
                            {linkedLoanMetrics.loanAccount.bank?.name ??
                                linkedLoanMetrics.loanAccount.name}
                        </span>
                    )}
                </div>
                <div className="mr-1 size-5 shrink-0">
                    <AccountTypeIcon type={account.type} />
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex items-center justify-between gap-6">
                    <div className="flex flex-col gap-1">
                        {isConnected ? (
                            <div className="-ml-2 px-2 py-1">
                                <AmountDisplay
                                    amountInCents={displayBalance}
                                    currencyCode={currencyCode}
                                    size="2xl"
                                    weight="medium"
                                />
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setUpdateBalanceOpen(true)}
                                className="-ml-2 cursor-pointer rounded-md px-2 py-1 text-left transition-colors hover:bg-muted"
                            >
                                <AmountDisplay
                                    amountInCents={displayBalance}
                                    currencyCode={currencyCode}
                                    size="2xl"
                                    weight="medium"
                                />
                            </button>
                        )}
                        <AmountTrendIndicator
                            isPositive={isPositive}
                            trend={Math.abs(displayDiff)}
                            label={__('vs last month')}
                            className="text-sm"
                            previousAmount={displayPreviousBalance}
                            currentAmount={displayBalance}
                            tooltipSide="bottom"
                            currencyCode={currencyCode}
                        />
                    </div>
                    <div
                        ref={chartWrapperRef}
                        className="h-[70px] w-full max-w-[250px] flex-1"
                    >
                        <ResponsiveContainer
                            width="100%"
                            height="100%"
                            initialDimension={{ width: 1, height: 1 }}
                        >
                            <LineChart data={sparklineData}>
                                {!tooltipHidden && (
                                    <Tooltip
                                        content={({ active, payload }) => {
                                            if (!active || !payload?.length)
                                                return null;
                                            const data = payload[0].payload as {
                                                date: string;
                                                value: number;
                                                investedAmount?: number | null;
                                                mortgageOwed?: number;
                                            };

                                            if (equityData) {
                                                const equity =
                                                    data.value -
                                                    (data.mortgageOwed ?? 0);
                                                return (
                                                    <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
                                                        <p className="mb-1 text-muted-foreground">
                                                            {data.date}
                                                        </p>
                                                        <div className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                                                            <span className="text-muted-foreground">
                                                                {__(
                                                                    'Market Value',
                                                                )}
                                                            </span>
                                                            <span className="text-right font-mono font-medium text-foreground tabular-nums">
                                                                <AmountDisplay
                                                                    amountInCents={
                                                                        data.value
                                                                    }
                                                                    currencyCode={
                                                                        currencyCode
                                                                    }
                                                                />
                                                            </span>
                                                            <span className="text-muted-foreground">
                                                                {__(
                                                                    'Mortgage Owed',
                                                                )}
                                                            </span>
                                                            <span className="text-right font-mono text-muted-foreground tabular-nums">
                                                                <AmountDisplay
                                                                    amountInCents={
                                                                        data.mortgageOwed ??
                                                                        0
                                                                    }
                                                                    currencyCode={
                                                                        currencyCode
                                                                    }
                                                                />
                                                            </span>
                                                            <span className="text-muted-foreground">
                                                                {__('Equity')}
                                                            </span>
                                                            <span
                                                                className={`text-right font-mono whitespace-nowrap tabular-nums ${equity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                                                            >
                                                                <AmountDisplay
                                                                    amountInCents={
                                                                        equity
                                                                    }
                                                                    currencyCode={
                                                                        currencyCode
                                                                    }
                                                                />
                                                            </span>
                                                        </div>
                                                    </div>
                                                );
                                            }

                                            const invested =
                                                supportsInvestedAmount(account)
                                                    ? (data.investedAmount ??
                                                      null)
                                                    : null;
                                            const gain =
                                                invested !== null
                                                    ? data.value - invested
                                                    : null;
                                            return (
                                                <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
                                                    <p className="mb-1 text-muted-foreground">
                                                        {data.date}
                                                    </p>
                                                    {invested !== null ? (
                                                        <div className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                                                            <span className="text-muted-foreground">
                                                                {__('Balance')}
                                                            </span>
                                                            <span className="text-right font-mono font-medium text-foreground tabular-nums">
                                                                <AmountDisplay
                                                                    amountInCents={
                                                                        data.value
                                                                    }
                                                                    currencyCode={
                                                                        currencyCode
                                                                    }
                                                                />
                                                            </span>
                                                            <span className="text-muted-foreground">
                                                                {__('Invested')}
                                                            </span>
                                                            <span className="text-right font-mono text-muted-foreground tabular-nums">
                                                                <AmountDisplay
                                                                    amountInCents={
                                                                        invested
                                                                    }
                                                                    currencyCode={
                                                                        currencyCode
                                                                    }
                                                                />
                                                            </span>
                                                            {gain !== null && (
                                                                <>
                                                                    <span className="text-muted-foreground">
                                                                        {__(
                                                                            'Gain/loss',
                                                                        )}
                                                                    </span>
                                                                    <span
                                                                        className={`text-right font-mono whitespace-nowrap tabular-nums ${gain >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}
                                                                    >
                                                                        {gain >=
                                                                        0
                                                                            ? '+'
                                                                            : ''}
                                                                        <AmountDisplay
                                                                            amountInCents={
                                                                                gain
                                                                            }
                                                                            currencyCode={
                                                                                currencyCode
                                                                            }
                                                                        />
                                                                    </span>
                                                                </>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="font-mono font-medium text-foreground tabular-nums">
                                                            <AmountDisplay
                                                                amountInCents={
                                                                    data.value
                                                                }
                                                                currencyCode={
                                                                    currencyCode
                                                                }
                                                            />
                                                        </p>
                                                    )}
                                                </div>
                                            );
                                        }}
                                    />
                                )}

                                <Line
                                    type="monotone"
                                    dataKey="value"
                                    stroke={accountMainLineColor}
                                    strokeWidth={2}
                                    dot={false}
                                />
                                {equityData && (
                                    <Line
                                        type="monotone"
                                        dataKey="mortgageOwed"
                                        stroke={mortgageLineColor}
                                        strokeWidth={1.5}
                                        strokeDasharray="4 3"
                                        dot={false}
                                        connectNulls
                                    />
                                )}
                                {!equityData &&
                                    supportsInvestedAmount(account) && (
                                        <Line
                                            type="monotone"
                                            dataKey="investedAmount"
                                            stroke={accountGainLineColor}
                                            strokeWidth={1.5}
                                            strokeDasharray="4 3"
                                            dot={false}
                                            connectNulls
                                        />
                                    )}
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            </CardContent>

            <UpdateBalanceDialog
                account={account}
                open={updateBalanceOpen}
                onOpenChange={setUpdateBalanceOpen}
                onSuccess={onBalanceUpdated}
            />
        </Card>
    );
}
