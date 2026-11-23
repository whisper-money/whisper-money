import { WholeAmount } from '@/components/cashflow/summary-card-parts';
import { PrivacyRevealButton } from '@/components/privacy-reveal-button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    StatTile,
    StatTileLabel,
    StatTileSkeleton,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { cn } from '@/lib/utils';
import { cashflow } from '@/routes';
import { SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, TrendingDown, TrendingUp } from 'lucide-react';

interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
}

interface CashflowSummaryCardProps {
    data?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    } | null;
    loading?: boolean;
}

export function CashflowSummaryCard({
    data,
    loading,
}: CashflowSummaryCardProps) {
    const { auth } = usePage<SharedData>().props;

    if (!auth?.user || loading || !data) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Cashflow')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-3 gap-4">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <StatTileSkeleton key={i} size="sm" />
                        ))}
                    </div>
                </CardContent>
            </Card>
        );
    }

    const { current } = data;
    const isPositiveNet = current.net >= 0;

    return (
        <Card className="col-span-3">
            <CardHeader className="gap-1">
                <div className="flex items-center justify-between">
                    <CardTitle>{__('Cashflow')}</CardTitle>
                    <div className="flex items-center gap-2">
                        <PrivacyRevealButton className="-my-2" />
                        <Link
                            href={cashflow().url}
                            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            {__('View details')}

                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </div>
                <CardDescription>
                    {__("This month's income and expenses")}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-3 gap-4">
                    <StatTile size="sm">
                        <StatTileLabel>{__('Income')}</StatTileLabel>
                        <StatTileValue>
                            <WholeAmount
                                amountInCents={current.income}
                                currencyCode={auth.user.currency_code}
                            />
                        </StatTileValue>
                    </StatTile>

                    <StatTile size="sm">
                        <StatTileLabel>{__('Expenses')}</StatTileLabel>
                        <StatTileValue>
                            <WholeAmount
                                amountInCents={current.expense}
                                currencyCode={auth.user.currency_code}
                            />
                        </StatTileValue>
                    </StatTile>

                    <StatTile size="sm">
                        <StatTileLabel>{__('Net')}</StatTileLabel>
                        <StatTileValue className="flex items-center gap-1">
                            {isPositiveNet ? (
                                <TrendingUp className="size-4 text-success" />
                            ) : (
                                <TrendingDown className="size-4 text-muted-foreground" />
                            )}
                            <WholeAmount
                                amountInCents={Math.abs(current.net)}
                                currencyCode={auth.user.currency_code}
                            />
                        </StatTileValue>
                    </StatTile>
                </div>

                {/* Savings rate footer */}
                <div className="mt-4 flex items-center justify-between border-t pt-3">
                    <span className="text-xs text-muted-foreground">
                        {__('Savings rate')}
                    </span>
                    <span
                        className={cn(
                            'text-sm font-medium',
                            current.savings_rate >= 20
                                ? 'text-success'
                                : current.savings_rate < 0 && 'text-warning',
                        )}
                    >
                        {current.savings_rate.toFixed(1)}%
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}
