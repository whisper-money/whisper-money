import { show as showBudget } from '@/actions/App/Http/Controllers/BudgetController';
import { WholeAmount } from '@/components/cashflow/summary-card-parts';
import { PrivacyRevealButton } from '@/components/privacy-reveal-button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    StatTile,
    StatTileLabel,
    StatTileSkeleton,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { cashflow } from '@/routes';
import { SharedData } from '@/types';
import { BudgetSeverity, percentUsed, usageSeverity } from '@/types/budget';
import { type SpendingLimit } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { formatPercent } from '@/utils/percent';
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
    /** The onboarding's monthly spending limit; null when there is none. */
    spendingLimit?: SpendingLimit | null;
    loading?: boolean;
}

export function CashflowSummaryCard({
    data,
    spendingLimit,
    loading,
}: CashflowSummaryCardProps) {
    const { auth } = usePage<SharedData>().props;
    const locale = useLocale();

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

                {spendingLimit && (
                    <SpendingLimitRow
                        limit={spendingLimit}
                        currencyCode={auth.user.currency_code}
                    />
                )}

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
                        {formatPercent(current.savings_rate, locale)}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Neutral while there is room, amber near the limit and red past it: the bar
 * is the one place on the card that warns, so it reads at a glance.
 */
const LIMIT_BAR_COLORS: Record<BudgetSeverity, string | undefined> = {
    ok: undefined,
    near: 'bg-warning',
    over: 'bg-destructive',
};

/**
 * The monthly spending limit the user set as their first target in the
 * onboarding, against what its budget has counted this period. Spent is the
 * budget's own figure, so it matches the budget page rather than the card's
 * expense total, which also covers what other budgets track.
 */
function SpendingLimitRow({
    limit,
    currencyCode,
}: {
    limit: SpendingLimit;
    currencyCode: string;
}) {
    const { formatAmount } = useFormatAmount();
    const used = percentUsed(limit.spent, limit.allocated);
    const remaining = limit.allocated - limit.spent;
    // Whole units like the figures above, except under one, where rounding
    // would print "€0 left" when €0.40 is.
    const formatRemaining = (cents: number) => {
        const amount = Math.abs(cents);

        return formatAmount(amount, currencyCode, {
            fractionDigits: amount > 0 && amount < 100 ? undefined : 0,
        });
    };

    return (
        <Link
            href={showBudget({ budget: limit.budget_id }).url}
            className="group mt-4 flex flex-col gap-2 border-t pt-3"
        >
            <div className="flex items-center justify-between gap-2 text-sm">
                <span className="font-medium group-hover:underline">
                    {__('Monthly spending limit')}
                </span>
                <span className="tabular-nums">
                    <WholeAmount
                        amountInCents={limit.spent}
                        currencyCode={currencyCode}
                    />{' '}
                    {__('of')}{' '}
                    <WholeAmount
                        amountInCents={limit.allocated}
                        currencyCode={currencyCode}
                    />
                </span>
            </div>
            <Progress
                value={Math.min(Math.max(used, 0), 100)}
                className="h-2"
                indicatorClassName={LIMIT_BAR_COLORS[usageSeverity(used)]}
                aria-label={__('Monthly spending limit')}
            />
            <span className="text-xs text-muted-foreground">
                {remaining < 0
                    ? __(':amount over the limit', {
                          amount: formatRemaining(remaining),
                      })
                    : spendingLimitLeftLabel(
                          formatRemaining(remaining),
                          limit.days_left,
                      )}
            </span>
        </Link>
    );
}

function spendingLimitLeftLabel(amount: string, daysLeft: number): string {
    if (daysLeft <= 0) {
        return __(':amount left on the last day', { amount });
    }

    return daysLeft === 1
        ? __(':amount left with 1 day to go', { amount })
        : __(':amount left with :count days to go', {
              amount,
              count: daysLeft,
          });
}
