import { MonthStripStatus } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsStatus } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';

/**
 * The colour of a month, shared by the strip, the bar chart and the legend so
 * the three never disagree about what green means.
 */
export const MONTH_STATUS_FILL: Record<MonthStripStatus, string> = {
    met: 'bg-emerald-600 dark:bg-emerald-500',
    missed: 'bg-orange-300 dark:bg-orange-400/70',
    in_progress:
        'border-[1.5px] border-dashed border-foreground/80 bg-background',
    none: 'bg-muted',
};

export function monthStatusLabel(status: MonthStripStatus): string {
    const labels: Record<MonthStripStatus, string> = {
        met: __('Met'),
        missed: __('Missed'),
        in_progress: __('In progress'),
        none: __('Before it existed'),
    };

    return labels[status];
}

/** Text colour for a signed difference: ahead reads green, behind orange. */
export function differenceClassName(difference: number): string {
    if (difference > 0) {
        return 'text-emerald-700 dark:text-emerald-400';
    }

    return difference < 0 ? 'text-orange-700 dark:text-orange-400' : '';
}

export function MonthStatusBadge({ status }: { status: MonthlySavingsStatus }) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                status === 'met' &&
                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                status === 'missed' &&
                    'bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
                status === 'in_progress' && 'bg-muted text-muted-foreground',
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
