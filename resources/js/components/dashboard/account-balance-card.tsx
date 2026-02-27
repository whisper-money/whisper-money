import { show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountName } from '@/components/accounts/account-name';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { BankLogo } from '@/components/bank-logo';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { supportsInvestedAmount } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';
import { AccountTypeIcon } from './account-type-icon';
import { AmountTrendIndicator } from './amount-trend-indicator';

interface AccountBalanceCardProps {
    account: AccountWithMetrics;
    loading?: boolean;
    onBalanceUpdated?: () => void;
}

export function AccountBalanceCard({
    account,
    loading,
    onBalanceUpdated,
}: AccountBalanceCardProps) {
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);
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

    const isPositive = account.diff >= 0;
    const isConnected = !!account.banking_connection_id;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">
                    <Link
                        href={show.url(account.id)}
                        className="-my-1 -ml-1.5 flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                    >
                        <BankLogo
                            src={account.bank.logo}
                            name={account.bank.name}
                            className="mr-2 inline-block size-5"
                        />

                        <AccountName
                            account={account}
                            length={{ min: 5, max: 15 }}
                        />
                    </Link>
                </CardTitle>
                <div className="text-xs font-medium text-muted-foreground">
                    <AccountTypeIcon
                        type={account.type}
                        className="mr-1 inline-block"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex items-center justify-between gap-6">
                    <div className="flex flex-col gap-1">
                        {isConnected ? (
                            <div className="-ml-2 px-2 py-1">
                                <AmountDisplay
                                    amountInCents={account.currentBalance}
                                    currencyCode={account.currency_code}
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
                                    amountInCents={account.currentBalance}
                                    currencyCode={account.currency_code}
                                    size="2xl"
                                    weight="medium"
                                />
                            </button>
                        )}
                        <AmountTrendIndicator
                            isPositive={isPositive}
                            trend={Math.abs(account.diff)}
                            label={__('vs last month')}
                            className="text-sm"
                            previousAmount={account.previousBalance}
                            currentAmount={account.currentBalance}
                            tooltipSide="bottom"
                            currencyCode={account.currency_code}
                        />
                    </div>
                    <div className="h-[70px] w-full max-w-[250px] flex-1">
                        <ResponsiveContainer
                            width="100%"
                            height="100%"
                            initialDimension={{ width: 1, height: 1 }}
                        >
                            <LineChart data={account.history}>
                                <Tooltip
                                    content={({ active, payload }) => {
                                        if (!active || !payload?.length)
                                            return null;
                                        const data = payload[0].payload as {
                                            date: string;
                                            value: number;
                                            investedAmount?: number | null;
                                        };
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
                                                                    account.currency_code
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
                                                                    account.currency_code
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
                                                                            account.currency_code
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
                                                                account.currency_code
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
                                    stroke={'var(--color-chart-2)'}
                                    strokeWidth={2}
                                    dot={false}
                                />
                                {supportsInvestedAmount(account) && (
                                    <Line
                                        type="monotone"
                                        dataKey="investedAmount"
                                        stroke="var(--color-chart-6)"
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
