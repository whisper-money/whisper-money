import { TransactionList } from '@/components/transactions/transaction-list';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLocale } from '@/hooks/use-locale';
import { monthDate, monthKey } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { Account, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { Label } from '@/types/label';
import { MonthlySavingsStats, SavingsGoal } from '@/types/savings-goal';
import { ServerTransaction } from '@/types/transaction';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { ReactNode, useMemo, useState } from 'react';
import { MonthStatusBadge, MonthStatusLegend } from './month-status';
import {
    CurrentMonthProgress,
    MonthTarget,
    SignedAmount,
} from './monthly-goal-figures';
import {
    MonthlySavingsBarChart,
    TargetLineLegend,
} from './monthly-savings-bar-chart';

interface Props {
    savingsGoal: SavingsGoal;
    monthly: MonthlySavingsStats;
    transactions: ServerTransaction[];
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    currencyCode: string;
}

/**
 * The page of a monthly goal: this month, the tally since it was created, every
 * month against its own target, and the contributions month by month.
 */
export function MonthlySavingsGoalView({
    savingsGoal,
    monthly,
    transactions,
    categories,
    accounts,
    banks,
    labels,
    currencyCode,
}: Props) {
    return (
        <div className="flex flex-col gap-6">
            <SummaryCards monthly={monthly} currencyCode={currencyCode} />

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-base">
                        {__('Saved each month')}
                    </CardTitle>
                    <div className="flex flex-wrap items-center gap-4">
                        <MonthStatusLegend statuses={['met', 'missed']} />
                        <TargetLineLegend label={__("That month's target")} />
                    </div>
                </CardHeader>
                <CardContent>
                    <MonthlySavingsBarChart
                        bars={monthly.history.slice(-12)}
                        currencyCode={currencyCode}
                        showDifference
                    />
                </CardContent>
            </Card>

            <div className="grid gap-6 xl:grid-cols-5">
                <HistoryTable
                    monthly={monthly}
                    currencyCode={currencyCode}
                    className="xl:col-span-3"
                />
                <Contributions
                    savingsGoal={savingsGoal}
                    months={monthly.history.map((entry) => entry.month)}
                    transactions={transactions}
                    categories={categories}
                    accounts={accounts}
                    banks={banks}
                    labels={labels}
                    className="xl:col-span-2"
                />
            </div>
        </div>
    );
}

function SummaryCard({
    title,
    children,
    footer,
    className,
}: {
    title: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <Card className={cn('gap-2', className)}>
            <CardContent className="flex flex-col gap-2">
                <span className="text-sm text-muted-foreground">{title}</span>
                {children}
                {footer && (
                    <span className="text-sm text-muted-foreground">
                        {footer}
                    </span>
                )}
            </CardContent>
        </Card>
    );
}

function SummaryCards({
    monthly,
    currencyCode,
}: {
    monthly: MonthlySavingsStats;
    currencyCode: string;
}) {
    const locale = useLocale();
    const { current } = monthly;
    const share =
        monthly.months_closed > 0
            ? Math.round((monthly.months_met / monthly.months_closed) * 100)
            : null;

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {current && (
                <SummaryCard
                    className="border-[1.5px] border-dashed border-foreground/60"
                    title={__(':month (in progress)', {
                        month: formatDate(
                            monthDate(current.month),
                            'MMMM',
                            locale,
                        ),
                    })}
                >
                    <CurrentMonthProgress
                        current={current}
                        currencyCode={currencyCode}
                    />
                </SummaryCard>
            )}
            <SummaryCard
                title={__('Months met')}
                footer={
                    share !== null
                        ? __(':percent% since you created it', {
                              percent: share,
                          })
                        : __('The first month is still in progress')
                }
            >
                <span className="text-2xl font-semibold">
                    {__(':met of :total', {
                        met: monthly.months_met,
                        total: monthly.months_closed,
                    })}
                </span>
            </SummaryCard>
            <SummaryCard
                title={__('Overall vs target')}
                footer={
                    <>
                        <AmountDisplay
                            amountInCents={monthly.cumulative_saved}
                            currencyCode={currencyCode}
                        />{' '}
                        {__('saved of')}{' '}
                        <AmountDisplay
                            amountInCents={monthly.cumulative_target}
                            currencyCode={currencyCode}
                        />{' '}
                        {__('planned')}
                    </>
                }
            >
                <SignedAmount
                    amount={monthly.cumulative_difference}
                    currencyCode={currencyCode}
                    className="text-2xl"
                />
            </SummaryCard>
            <SummaryCard
                title={__('Current streak')}
                footer={__('Best streak: :count', {
                    count: monthsCount(monthly.best_streak),
                })}
            >
                <span className="text-2xl font-semibold">
                    {monthsCount(monthly.streak)}
                </span>
            </SummaryCard>
        </div>
    );
}

function monthsCount(count: number): string {
    return count === 1 ? __('1 month') : __(':count months', { count });
}

function HistoryTable({
    monthly,
    currencyCode,
    className,
}: {
    monthly: MonthlySavingsStats;
    currencyCode: string;
    className?: string;
}) {
    const locale = useLocale();

    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle className="text-base">{__('History')}</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{__('Month')}</TableHead>
                            <TableHead className="text-right">
                                {__('Target')}
                            </TableHead>
                            <TableHead className="text-right">
                                {__('Saved')}
                            </TableHead>
                            <TableHead className="text-right">
                                {__('Difference')}
                            </TableHead>
                            <TableHead>{__('Status')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {[...monthly.history].reverse().map((entry) => (
                            <TableRow key={entry.month}>
                                <TableCell>
                                    {formatMonthYear(
                                        monthDate(entry.month),
                                        locale,
                                    )}
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                    <MonthTarget
                                        month={entry}
                                        currencyCode={currencyCode}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <AmountDisplay
                                        amountInCents={entry.saved}
                                        currencyCode={currencyCode}
                                    />
                                </TableCell>
                                <TableCell className="text-right">
                                    {entry.status === 'in_progress' ? (
                                        <span className="text-muted-foreground tabular-nums">
                                            {entry.difference < 0 ? (
                                                <>
                                                    <AmountDisplay
                                                        amountInCents={
                                                            -entry.difference
                                                        }
                                                        currencyCode={
                                                            currencyCode
                                                        }
                                                    />{' '}
                                                    {__('to go')}
                                                </>
                                            ) : (
                                                __('Target met')
                                            )}
                                        </span>
                                    ) : (
                                        <SignedAmount
                                            amount={entry.difference}
                                            currencyCode={currencyCode}
                                        />
                                    )}
                                </TableCell>
                                <TableCell>
                                    <MonthStatusBadge status={entry.status} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/**
 * The tagged transactions of one month at a time. The page receives every
 * tagged transaction, because the link dialog needs the whole set to save it
 * back, so stepping through months happens here without a round trip.
 */
function Contributions({
    savingsGoal,
    months,
    transactions,
    categories,
    accounts,
    banks,
    labels,
    className,
}: {
    savingsGoal: SavingsGoal;
    months: string[];
    transactions: ServerTransaction[];
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    className?: string;
}) {
    const locale = useLocale();
    const [index, setIndex] = useState(Math.max(0, months.length - 1));
    const month = months[index] ?? monthKey(new Date());

    const inMonth = useMemo(
        () =>
            transactions.filter((transaction) =>
                transaction.transaction_date.startsWith(month),
            ),
        [transactions, month],
    );

    return (
        <Card className={className}>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-base">
                    {__('Contributions')}
                </CardTitle>
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={__('Previous month')}
                        disabled={index <= 0}
                        onClick={() => setIndex(index - 1)}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <span className="min-w-28 px-2 text-center text-sm font-medium">
                        {formatMonthYear(monthDate(month), locale)}
                    </span>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={__('Next month')}
                        disabled={index >= months.length - 1}
                        onClick={() => setIndex(index + 1)}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {inMonth.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {__('Nothing tagged with this goal in this month yet.')}
                    </p>
                ) : (
                    <TransactionList
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        labels={labels}
                        transactions={inMonth}
                        pageSize={10}
                        showActionsMenu={false}
                        maxHeight={480}
                        hiddenLabelId={savingsGoal.label_id ?? undefined}
                    />
                )}
            </CardContent>
        </Card>
    );
}
