import { show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { AccountTypeIcon } from '@/components/dashboard/account-type-icon';
import { AmountTrendIndicator } from '@/components/dashboard/amount-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { cn } from '@/lib/utils';
import { formatAccountType, supportsInvestedAmount } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { type ReactNode, useMemo, useState } from 'react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';
import { Button } from '../ui/button';
import { UpdateBalanceDialog } from './update-balance-dialog';

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

interface AccountListCardProps {
    account: AccountWithMetrics;
    loading?: boolean;
    onBalanceUpdated?: () => void;
    linkedLoanMetrics?: LinkedLoanMetrics;
    displayCurrencyCode?: string;
    dragHandle?: ReactNode;
}

export function AccountListCard({
    account,
    loading,
    onBalanceUpdated,
    linkedLoanMetrics,
    displayCurrencyCode,
    dragHandle,
}: AccountListCardProps) {
    const currencyCode = displayCurrencyCode ?? account.currency_code;
    const { accountMainLineColor, accountGainLineColor, mortgageLineColor } =
        useChartColors();
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);

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
            mortgageOwed,
            marketValue,
            dualHistory,
        };
    }, [hasMortgage, account, linkedLoanMetrics]);

    if (loading) {
        return (
            <Card className="w-full">
                <CardContent className="p-6">
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="h-10 w-10 animate-pulse rounded-full bg-gray-200 dark:bg-gray-700" />
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                    <div className="h-6 w-40 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                </div>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <div className="h-8 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                <div className="h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                            </div>
                        </div>
                        <div className="h-[100px] w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                    </div>
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

    // Choose sparkline data: dual-line history for merged, account history for normal
    const sparklineData = equityData ? equityData.dualHistory : account.history;

    return (
        <Card className="w-full py-0">
            <CardContent className="p-4">
                <div className="flex flex-col gap-4">
                    <div className="flex max-w-full flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <div className="flex min-w-0 flex-col gap-1">
                                <Link
                                    href={show.url(account.id)}
                                    className="-my-1 -ml-1.5 flex min-w-0 items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                                >
                                    <h3 className="flex min-w-0 items-center gap-2 font-semibold">
                                        {account.bank && (
                                            <BankLogo
                                                src={account.bank.logo}
                                                name={account.bank.name}
                                                className="size-4 shrink-0"
                                                fallback="letter"
                                            />
                                        )}
                                        <AccountName
                                            account={account}
                                            length={{ min: 8, max: 25 }}
                                            className="truncate"
                                        />
                                    </h3>
                                </Link>
                                <div className="hidden items-center gap-2 text-sm text-muted-foreground sm:flex">
                                    {hasMortgage &&
                                    linkedLoanMetrics.loanAccount ? (
                                        <span className="flex items-center gap-1">
                                            {__('Mortgage at')}{' '}
                                            {linkedLoanMetrics.loanAccount
                                                .bank && (
                                                <BankLogo
                                                    src={
                                                        linkedLoanMetrics
                                                            .loanAccount.bank
                                                            .logo
                                                    }
                                                    name={
                                                        linkedLoanMetrics
                                                            .loanAccount.bank
                                                            .name
                                                    }
                                                    className="size-3.5 shrink-0"
                                                    fallback="letter"
                                                />
                                            )}
                                            {linkedLoanMetrics.loanAccount.bank
                                                ?.name ??
                                                linkedLoanMetrics.loanAccount
                                                    .name}
                                        </span>
                                    ) : (
                                        <span>
                                            {account.bank?.name ||
                                                formatAccountType(account.type)}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="flex shrink-0 flex-col items-start sm:items-end">
                            {isConnected ? (
                                <div className="-ml-2 px-2 py-1 sm:-mr-2 sm:ml-0">
                                    <AmountDisplay
                                        amountInCents={displayBalance}
                                        currencyCode={currencyCode}
                                        size="2xl"
                                        weight="bold"
                                    />
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setUpdateBalanceOpen(true)}
                                    className="-ml-2 cursor-pointer rounded-md px-2 py-1 transition-colors hover:bg-muted sm:-mr-2 sm:ml-0"
                                >
                                    <AmountDisplay
                                        amountInCents={displayBalance}
                                        currencyCode={currencyCode}
                                        size="2xl"
                                        weight="bold"
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
                    </div>

                    <div className="h-[100px] w-full">
                        <ResponsiveContainer
                            width="100%"
                            height="100%"
                            initialDimension={{ width: 1, height: 1 }}
                        >
                            <LineChart data={sparklineData}>
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
                                                <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-sm shadow-xl">
                                                    <p className="mb-1 text-muted-foreground">
                                                        {data.date}
                                                    </p>
                                                    <div className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                                                        <span className="text-muted-foreground">
                                                            {__('Market Value')}
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

                                        const invested = supportsInvestedAmount(
                                            account,
                                        )
                                            ? (data.investedAmount ?? null)
                                            : null;
                                        const gain =
                                            invested !== null
                                                ? data.value - invested
                                                : null;
                                        return (
                                            <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-sm shadow-xl">
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
                                                                    {gain >= 0
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
                    <div className="flex items-center gap-2">
                        <div className="relative size-5 shrink-0 text-muted-foreground">
                            <AccountTypeIcon
                                type={account.type}
                                className={cn(
                                    'transition-opacity',
                                    dragHandle && 'group-hover:opacity-0',
                                )}
                            />
                            {dragHandle && (
                                // The grip glyph is narrower than the type icon;
                                // nudge it to the left edge to line up with it.
                                <span className="absolute inset-0 flex -translate-x-1 items-center justify-start opacity-0 transition-opacity group-hover:opacity-100">
                                    {dragHandle}
                                </span>
                            )}
                        </div>

                        {!isConnected && (
                            <Button
                                className="cursor-pointer"
                                variant="secondary"
                                onClick={() => setUpdateBalanceOpen(true)}
                            >
                                {account.type === 'loan'
                                    ? __('Update owed amount')
                                    : account.type === 'real_estate'
                                      ? __('Update market value')
                                      : __('Update balance')}
                            </Button>
                        )}

                        <Link href={show.url(account.id)} className="ml-auto">
                            <Button className="cursor-pointer" variant="ghost">
                                {__('Details')} &rarr;
                            </Button>
                        </Link>
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
