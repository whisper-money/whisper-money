import { show } from '@/actions/App/Http/Controllers/AccountController';
import { EncryptedText } from '@/components/encrypted-text';
import { Card, CardContent } from '@/components/ui/card';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { Link } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';

interface AccountListCardProps {
    account: AccountWithMetrics;
    loading?: boolean;
}

export function AccountListCard({ account, loading }: AccountListCardProps) {
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

    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: account.currency_code,
    });

    const isPositive = account.diff >= 0;
    const TrendIcon = isPositive ? TrendingUp : TrendingDown;
    const trendColorClass = isPositive
        ? 'text-green-600 dark:text-green-400'
        : 'text-red-600 dark:text-red-400';

    return (
        <Link href={show.url(account.id)} className="block">
            <Card className="w-full cursor-pointer transition-all hover:border-primary/50 hover:shadow-md">
                <CardContent className="p-6">
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                {account.bank?.logo ? (
                                    <img
                                        src={account.bank.logo}
                                        alt={account.bank.name}
                                        className="size-10 rounded-full object-contain"
                                    />
                                ) : (
                                    <div className="flex size-10 items-center justify-center rounded-full bg-muted">
                                        <span className="text-sm font-medium text-muted-foreground">
                                            {account.bank?.name?.charAt(0) ||
                                                '?'}
                                        </span>
                                    </div>
                                )}
                                <div className="flex flex-col">
                                    <span className="text-sm text-muted-foreground">
                                        {account.bank?.name || 'Unknown Bank'}
                                    </span>
                                    <h3 className="text-lg font-semibold">
                                        <EncryptedText
                                            encryptedText={account.name}
                                            iv={account.name_iv}
                                            length={{ min: 8, max: 25 }}
                                        />
                                    </h3>
                                </div>
                            </div>
                            <div className="flex flex-col items-end">
                                <span className="text-2xl font-bold tabular-nums">
                                    {formatter.format(
                                        account.currentBalance / 100,
                                    )}
                                </span>
                                <div
                                    className={`flex items-center gap-1 text-sm ${trendColorClass}`}
                                >
                                    <TrendIcon className="h-4 w-4" />
                                    <span className="tabular-nums">
                                        {formatter.format(
                                            Math.abs(account.diff) / 100,
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        vs last month
                                    </span>
                                </div>
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
                                                        {formatter.format(
                                                            data.value / 100,
                                                        )}
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
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}
