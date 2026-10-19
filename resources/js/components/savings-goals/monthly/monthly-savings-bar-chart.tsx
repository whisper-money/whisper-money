import { AmountDisplay } from '@/components/ui/amount-display';
import { useLocale } from '@/hooks/use-locale';
import { monthDate } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsStatus } from '@/types/savings-goal';
import { formatMonthFromYearMonth, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { RefObject, useLayoutEffect, useRef, useState } from 'react';
import {
    differenceClassName,
    hasNoVerdict,
    isJudged,
    MONTH_STATUS_FILL,
    monthStatusLabel,
} from './month-status';

/** Room for a signed amount above each bar without touching its neighbours. */
const MIN_COLUMN_WIDTH = 56;

/** So a goal in its first months draws bars, not one slab across the card. */
const MAX_COLUMN_WIDTH = 96;

/** The gap between columns, gap-3. */
const COLUMN_GAP = 12;

/**
 * The width of an element, kept up to date. Null until measured, and where the
 * browser cannot measure (tests).
 */
function useWidth(ref: RefObject<HTMLElement | null>): number | null {
    const [width, setWidth] = useState<number | null>(null);

    useLayoutEffect(() => {
        const element = ref.current;

        if (!element || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [ref]);

    return width;
}

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
    const container = useRef<HTMLDivElement>(null);
    const width = useWidth(container);
    // As many of the latest months as fit whole: the newest is the one being
    // looked at, and a bar cut in half reads as a smaller amount.
    const fitting =
        width === null
            ? bars.length
            : Math.max(
                  1,
                  Math.floor(
                      (width + COLUMN_GAP) / (MIN_COLUMN_WIDTH + COLUMN_GAP),
                  ),
              );
    const shown = bars.slice(-fitting);
    const scale = Math.max(
        1,
        ...shown.flatMap((bar) =>
            hasNoVerdict(bar.status) ? [bar.saved] : [bar.saved, bar.target],
        ),
    );
    const percentOf = (value: number) =>
        `${(Math.max(0, value) / scale) * 100}%`;

    return (
        <div ref={container} className="min-w-0">
            <div
                className="grid items-end justify-center gap-3"
                style={{
                    gridTemplateColumns: `repeat(${shown.length}, minmax(${MIN_COLUMN_WIDTH}px, ${MAX_COLUMN_WIDTH}px))`,
                }}
            >
                {shown.map((bar) => {
                    const label = formatMonthFromYearMonth(bar.month, locale);
                    const barLabel = `${formatMonthYear(monthDate(bar.month), locale)}: ${monthStatusLabel(bar.status)}`;

                    return (
                        <div key={bar.month} className="flex flex-col gap-2">
                            {showDifference && (
                                <span className="text-center text-xs tabular-nums">
                                    {!isJudged(bar.status) ? (
                                        <span className="text-muted-foreground">
                                            {bar.status === 'in_progress'
                                                ? __('in progress')
                                                : monthStatusLabel(
                                                      bar.status,
                                                  ).toLowerCase()}
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
                                {bar.saved > 0 ? (
                                    <div
                                        className={cn(
                                            'w-full rounded-t-md',
                                            MONTH_STATUS_FILL[bar.status],
                                        )}
                                        style={{
                                            height: percentOf(bar.saved),
                                        }}
                                    />
                                ) : (
                                    // Nothing saved: a flat baseline, not a
                                    // zero-height box whose border draws a
                                    // wavy line.
                                    <div className="h-px w-full bg-border" />
                                )}
                                {/* A month without a verdict has no target to draw. */}
                                {!hasNoVerdict(bar.status) && (
                                    <div
                                        className="absolute -inset-x-1 h-0.5 bg-foreground"
                                        // Kept inside the box: the highest target sits at
                                        // 100%, where the scroll container would clip it.
                                        style={{
                                            bottom: `min(${percentOf(bar.target)}, calc(100% - 2px))`,
                                        }}
                                        aria-hidden="true"
                                    />
                                )}
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
