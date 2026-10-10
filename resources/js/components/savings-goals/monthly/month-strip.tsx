import { useLocale } from '@/hooks/use-locale';
import { monthDate, monthStrip } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import { MonthlySavingsMonth } from '@/types/savings-goal';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { MONTH_STATUS_FILL, monthStatusLabel } from './month-status';

interface Props {
    history: MonthlySavingsMonth[];
    /** YYYY-MM of the last cell, normally the current month. */
    endMonth: string;
}

/**
 * The last twelve months of a goal at a glance: one cell per month, coloured by
 * whether it was met, with the month's initial underneath.
 */
export function MonthStrip({ history, endMonth }: Props) {
    const locale = useLocale();
    const cells = monthStrip(history, endMonth);

    return (
        <div className="flex flex-col gap-1.5">
            <div
                className="grid grid-cols-12 gap-[3px]"
                role="list"
                aria-label={__('Last 12 months')}
            >
                {cells.map((cell) => {
                    const name = formatDate(
                        monthDate(cell.month),
                        'MMMM yyyy',
                        locale,
                    );

                    return (
                        <div
                            key={cell.month}
                            role="listitem"
                            title={`${name}: ${monthStatusLabel(cell.status)}`}
                            aria-label={`${name}: ${monthStatusLabel(cell.status)}`}
                            className={cn(
                                'h-4 rounded-[3px]',
                                MONTH_STATUS_FILL[cell.status],
                            )}
                        />
                    );
                })}
            </div>
            <div
                className="grid grid-cols-12 gap-[3px] text-center text-[10px] text-muted-foreground"
                aria-hidden="true"
            >
                {cells.map((cell) => (
                    <span key={cell.month}>
                        {formatDate(monthDate(cell.month), 'MMMM', locale)
                            .charAt(0)
                            .toUpperCase()}
                    </span>
                ))}
            </div>
        </div>
    );
}
