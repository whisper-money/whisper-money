import { AmountDisplay } from '@/components/ui/amount-display';
import { useLocale } from '@/hooks/use-locale';
import { monthDate } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsStatus } from '@/types/savings-goal';
import { formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { MONTH_STATUS_FILL, monthStatusLabel } from './month-status';

export interface MonthlySavingsBar {
    /** YYYY-MM */
    month: string;
    saved: number;
    target: number;
    status: MonthlySavingsStatus;
}

interface Props {
    bars: MonthlySavingsBar[];
    currencyCode: string;
    /** Bar area height in pixels. */
    height?: number;
    /** Writes each closed month's difference above its bar. */
    showDifference?: boolean;
}

/**
 * Saved per month as a bar, with that month's own target as a tick across it,
 * so a raised target reads as a step rather than rewriting the past. Shared by
 * a goal's page and the cashflow card, which sums every goal.
 */
export function MonthlySavingsBarChart({
    bars,
    currencyCode,
    height = 200,
    showDifference = false,
}: Props) {
    const locale = useLocale();
    const scale = Math.max(
        1,
        ...bars.flatMap((bar) => [bar.saved, bar.target]),
    );
    const percentOf = (value: number) =>
        `${(Math.max(0, value) / scale) * 100}%`;

    return (
        <div className="overflow-x-auto">
            <div
                className="grid min-w-[420px] items-end gap-3"
                style={{
                    gridTemplateColumns: `repeat(${bars.length}, minmax(0, 1fr))`,
                }}
            >
                {bars.map((bar) => {
                    const label = formatMonthFromYearMonth(bar.month, locale);

                    return (
                        <div key={bar.month} className="flex flex-col gap-2">
                            {showDifference && (
                                <span className="text-center text-xs tabular-nums">
                                    {bar.status === 'in_progress' ? (
                                        <span className="text-muted-foreground">
                                            {__('in progress')}
                                        </span>
                                    ) : (
                                        <AmountDisplay
                                            amountInCents={
                                                bar.saved - bar.target
                                            }
                                            currencyCode={currencyCode}
                                            showSign
                                            maximumFractionDigits={0}
                                            minimumFractionDigits={0}
                                            className={
                                                bar.saved >= bar.target
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : 'text-orange-700 dark:text-orange-400'
                                            }
                                        />
                                    )}
                                </span>
                            )}
                            <div
                                className="relative flex items-end"
                                style={{ height }}
                                title={`${monthDate(bar.month).getFullYear()} ${label}: ${monthStatusLabel(bar.status)}`}
                            >
                                <div
                                    className={cn(
                                        'w-full rounded-t-md',
                                        MONTH_STATUS_FILL[bar.status],
                                        bar.status === 'in_progress' &&
                                            'border-emerald-600 bg-emerald-100 dark:border-emerald-500 dark:bg-emerald-950',
                                    )}
                                    style={{ height: percentOf(bar.saved) }}
                                />
                                <div
                                    className="absolute -inset-x-1 h-0.5 bg-foreground"
                                    style={{ bottom: percentOf(bar.target) }}
                                    aria-hidden="true"
                                />
                            </div>
                            <span className="text-center text-xs text-muted-foreground">
                                {label}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export function TargetLineLegend({ label }: { label: string }) {
    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="h-0.5 w-3.5 bg-foreground" />
            {label}
        </span>
    );
}
