import { EncryptedText } from '@/components/encrypted-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Account } from '@/types/account';
import { AmountTrendIndicator } from './amount-trend-indicator';
import { AccountTypeIcon } from './account-type-icon';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';

interface AccountBalanceCardProps {
    account: Account & {
        currentBalance: number;
        diff: number;
        history: Array<{ date: string; value: number }>;
    };
    loading?: boolean;
}

export function AccountBalanceCard({
    account,
    loading,
}: AccountBalanceCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">
                        Loading...
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-20 w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: account.currency_code,
    });

    const isPositive = account.diff >= 0;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">
                    <EncryptedText
                        encryptedText={account.name}
                        iv={account.name_iv}
                        length={{ min: 5, max: 15 }}
                    />
                </CardTitle>
                <div className="text-xs font-medium text-muted-foreground">
                    <AccountTypeIcon type={account.type} className="inline-block mr-1" />
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex gap-6 items-center justify-between">
                    <div className='flex flex-col gap-1'>
                        <div className="text-2xl font-medium">
                            {formatter.format(account.currentBalance / 100)}
                        </div>
                        <AmountTrendIndicator
                            isPositive={isPositive}
                            trend={formatter.format(Math.abs(account.diff) / 100)}
                            label="vs last month"
                            className='text-sm'
                        />
                    </div>
                    <div className="h-[70px] max-w-[250px] w-full flex-1">
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
                                            <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
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
                                    stroke={'var(--color-zinc-700)'}
                                    strokeWidth={2}
                                    dot={false}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
