import { AmountDisplay } from '@/components/ui/amount-display';
import { useLocale } from '@/hooks/use-locale';
import { monthDate } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsStatus } from '@/types/savings-goal';
import { formatMonthFromYearMonth, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { useLayoutEffect, useRef } from 'react';
import {
    differenceClassName,
    MONTH_STATUS_FILL,
    monthStatusLabel,
} from './month-status';

/** Room for a signed amount above each bar without touching its neighbours. */
const MIN_COLUMN_WIDTH = 56;

/** So a goal in its first months draws bars, not one slab across the card. */
const MAX_COLUMN_WIDTH = 96;

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
    const scroller = useRef<HTMLDivElement>(null);
    const lastMonth = bars.at(-1)?.month;

    // When the months outgrow the card, open on the latest ones: that is the
    // month being looked at, and the oldest are a scroll away.
    useLayoutEffect(() => {
        const element = scroller.current;

        if (element) {
            element.scrollLeft = element.scrollWidth;
        }
    }, [lastMonth]);

    return (
        <div ref={scroller} className="overflow-x-auto">
            {/* w-max + mx-auto rather than justify-center: centred tracks that
                outgrow a narrow card spill out of both sides, and the left half
                can't be scrolled to. */}
            <div
                className="mx-auto grid w-max items-end gap-3"
                style={{
                    gridTemplateColumns: `repeat(${bars.length}, minmax(${MIN_COLUMN_WIDTH}px, ${MAX_COLUMN_WIDTH}px))`,
                }}
            >
                {bars.map((bar) => {
                    const label = formatMonthFromYearMonth(bar.month, locale);
                    const barLabel = `${formatMonthYear(monthDate(bar.month), locale)}: ${monthStatusLabel(bar.status)}`;

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
                                            className={differenceClassName(
                                                bar.saved - bar.target,
                                            )}
                                        />
                                    )}
                                </span>
                            )}
                            <div
                                className="relative flex items-end"
                                style={{ height }}
                                role="img"
                                aria-label={barLabel}
                                title={barLabel}
                            >
                                <div
                                    className={cn(
                                        'w-full rounded-t-md',
                                        MONTH_STATUS_FILL[bar.status],
                                    )}
                                    style={{ height: percentOf(bar.saved) }}
                                />
                                <div
                                    className="absolute -inset-x-1 h-0.5 bg-foreground"
                                    // Kept inside the box: the highest target sits at
                                    // 100%, where the scroll container would clip it.
                                    style={{
                                        bottom: `min(${percentOf(bar.target)}, calc(100% - 2px))`,
                                    }}
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
