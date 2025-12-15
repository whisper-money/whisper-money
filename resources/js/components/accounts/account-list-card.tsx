import { show } from '@/actions/App/Http/Controllers/AccountController';
import { AmountTrendIndicator } from '@/components/dashboard/amount-trend-indicator';
import { EncryptedText } from '@/components/encrypted-text';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';
import { Button } from '../ui/button';
import { UpdateBalanceDialog } from './update-balance-dialog';

interface AccountListCardProps {
    account: AccountWithMetrics;
    loading?: boolean;
    onBalanceUpdated?: () => void;
}

export function AccountListCard({
    account,
    loading,
    onBalanceUpdated,
}: AccountListCardProps) {
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);

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

    const isPositive = account.diff >= 0;

    return (
        <Card className="w-full py-0">
            <CardContent className="p-4">
                <div className="flex flex-col gap-4">
                    <div className="flex max-w-full flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <div className="flex flex-col gap-1">
                                <Link
                                    href={show.url(account.id)}
                                    className="-my-1 -ml-1.5 flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                                >
                                    <h3 className="flex items-center gap-2 font-semibold">
                                        {account.bank?.logo ? (
                                            <img
                                                src={account.bank.logo}
                                                alt={account.bank.name}
                                                className="size-4 rounded-full object-contain"
                                            />
                                        ) : (
                                            <div className="flex size-4 items-center justify-center rounded-full bg-muted">
                                                <span className="text-sm font-medium text-muted-foreground">
                                                    {account.bank?.name?.charAt(
                                                        0,
                                                    ) || '?'}
                                                </span>
                                            </div>
                                        )}
                                        <EncryptedText
                                            encryptedText={account.name}
                                            iv={account.name_iv}
                                            length={{ min: 8, max: 25 }}
                                            className="truncate"
                                        />
                                    </h3>
                                </Link>
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <span>
                                        {account.bank?.name || 'Unknown Bank'}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-col items-end">
                            <button
                                type="button"
                                onClick={() => setUpdateBalanceOpen(true)}
                                className="-mr-2 cursor-pointer rounded-md px-2 py-1 transition-colors hover:bg-muted"
                            >
                                <AmountDisplay
                                    amountInCents={account.currentBalance}
                                    currencyCode={account.currency_code}
                                    size="2xl"
                                    weight="bold"
                                />
                            </button>
                            <AmountTrendIndicator
                                isPositive={isPositive}
                                trend={Math.abs(account.diff)}
                                label="vs last month"
                                className="text-sm"
                                previousAmount={account.previousBalance}
                                currentAmount={account.currentBalance}
                                tooltipSide="bottom"
                                currencyCode={account.currency_code}
                            />
                        </div>
                    </div>
                    <div className="h-[100px] w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={account.history}>
                                <Tooltip
                                    content={({ active, payload }) => {
                                        if (!active || !payload?.length)
                                            return null;
                                        const data = payload[0].payload as {
                                            date: string;
                                            value: number;
                                        };
                                        return (
                                            <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-sm shadow-xl">
                                                <p className="mb-0.5 text-muted-foreground">
                                                    {data.date}
                                                </p>
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
                                            </div>
                                        );
                                    }}
                                />
                                <Line
                                    type="monotone"
                                    dataKey="value"
                                    stroke="var(--color-chart-2)"
                                    strokeWidth={2}
                                    dot={false}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="flex justify-between">
                        <Button
                            className="cursor-pointer"
                            variant="secondary"
                            onClick={() => setUpdateBalanceOpen(true)}
                        >
                            Update balance
                        </Button>

                        <Link href={show.url(account.id)}>
                            <Button className="cursor-pointer" variant="ghost">
                                Details &rarr;
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
