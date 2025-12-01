import { EncryptedText } from '@/components/encrypted-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Account } from '@/types/account';
import { ArrowDownIcon, ArrowUpIcon } from 'lucide-react';
import { Line, LineChart, ResponsiveContainer } from 'recharts';

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
                    />
                </CardTitle>
                <div className="text-muted-foreground text-xs font-medium">
                    {account.type}
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex items-center justify-between">
                    <div>
                        <div className="text-2xl font-bold">
                            {formatter.format(account.currentBalance / 100)}
                        </div>
                        <p className="text-muted-foreground flex items-center text-xs">
                            <span
                                className={
                                    isPositive
                                        ? 'text-green-600'
                                        : 'text-red-600'
                                }
                            >
                                {isPositive ? (
                                    <ArrowUpIcon className="mr-1 inline size-3" />
                                ) : (
                                    <ArrowDownIcon className="mr-1 inline size-3" />
                                )}
                                {formatter.format(Math.abs(account.diff) / 100)}
                            </span>
                            <span className="ml-1">vs last month</span>
                        </p>
                    </div>
                    <div className="h-[60px] w-[100px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={account.history}>
                                <Line
                                    type="monotone"
                                    dataKey="value"
                                    stroke={isPositive ? '#16a34a' : '#dc2626'}
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
