import { show } from '@/actions/App/Http/Controllers/AccountController';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { EncryptedText } from '@/components/encrypted-text';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
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
                        Loading...
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-20 w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const isPositive = account.diff >= 0;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">
                    <Link
                        href={show.url(account.id)}
                        className="-my-1 -ml-1.5 flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                    >
                        {account.bank.logo && (
                            <img
                                src={account.bank.logo}
                                alt={account.bank.name}
                                className="mr-2 inline-block size-5 rounded-full object-contain"
                            />
                        )}

                        <EncryptedText
                            encryptedText={account.name}
                            iv={account.name_iv}
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
                    <div className="h-[70px] w-full max-w-[250px] flex-1">
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
                                    stroke={'var(--color-chart-2)'}
                                    strokeWidth={2}
                                    dot={false}
                                />
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
