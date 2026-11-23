import { Badge } from '@/components/ui/badge';
import { MonthStripStatus } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsStatus } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { ReactNode } from 'react';

/**
 * The colour of a month, shared by the strip, the bar chart and the legend so
 * the three never disagree about what a met month looks like.
 */
export const MONTH_STATUS_FILL: Record<MonthStripStatus, string> = {
    met: 'bg-success',
    missed: 'bg-warning/50',
    in_progress:
        'border-[1.5px] border-dashed border-foreground/80 bg-background',
    partial: 'bg-success/30',
    archived: 'bg-muted-foreground/30',
    none: 'bg-muted',
};

/** The "Monthly" pill on a goal's card and page. */
export function MonthlyBadge({ children }: { children: ReactNode }) {
    return (
        <Badge
            variant="outline"
            className="border-success/30 bg-success/10 text-success"
        >
            {children}
        </Badge>
    );
}

export function monthStatusLabel(status: MonthStripStatus): string {
    const labels: Record<MonthStripStatus, string> = {
        met: __('Met'),
        missed: __('Missed'),
        in_progress: __('In progress'),
        partial: __('Partial month'),
        archived: __('Archived'),
        none: __('Before it existed'),
    };

    return labels[status];
}

/** Whether a month got a verdict, so its target and difference mean something. */
export function isJudged(status: MonthlySavingsStatus): boolean {
    return status === 'met' || status === 'missed';
}

/** A month shown without a target or a verdict: partial or archived. */
export function hasNoVerdict(status: MonthlySavingsStatus): boolean {
    return status === 'partial' || status === 'archived';
}

/**
 * The legend for a set of months: the given statuses, plus "partial" and
 * "archived" only when one of the months is, so goals without one are not
 * taught a term they will never see.
 */
export function legendStatuses(
    base: MonthStripStatus[],
    months: { status: MonthlySavingsStatus }[],
): MonthStripStatus[] {
    const extra = (['partial', 'archived'] as const).filter((status) =>
        months.some((month) => month.status === status),
    );

    return [...base, ...extra];
}

/**
 * What stands in for "months met" before any month was judged: the first one
 * is still running, or — for an archived goal — none ever will be.
 */
export function noJudgedMonthYet(archived: boolean): string {
    return archived
        ? __('No full month before it was archived')
        : __('The first month is still in progress');
}

/**
 * Text colour for a signed difference, a delta: ahead reads `success`, behind
 * `muted-foreground` (the month's verdict, not this figure, carries the warning).
 */
export function differenceClassName(difference: number): string {
    if (difference > 0) {
        return 'text-success';
    }

    return difference < 0 ? 'text-muted-foreground' : '';
}

export function MonthStatusBadge({ status }: { status: MonthlySavingsStatus }) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                status === 'met' && 'bg-success/10 text-success',
                status === 'missed' && 'bg-warning/10 text-warning',
                (status === 'in_progress' || hasNoVerdict(status)) &&
                    'bg-muted text-muted-foreground',
            )}
        >
            {monthStatusLabel(status)}
        </span>
    );
}

export function MonthStatusLegend({
    statuses = ['met', 'missed', 'in_progress', 'none'],
}: {
    statuses?: MonthStripStatus[];
}) {
    return (
        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
            {statuses.map((status) => (
                <span key={status} className="flex items-center gap-1.5">
                    <span
                        className={cn(
                            'size-3 rounded-[3px]',
                            MONTH_STATUS_FILL[status],
                        )}
                    />
                    {monthStatusLabel(status)}
                </span>
            ))}
        </div>
    );
}
