import { format, subDays } from 'date-fns';
import { useMemo } from 'react';

/**
 * The trailing 30-day window the dashboard spending cards report on, as
 * `yyyy-MM-dd` bounds ready to hand to a transactions filter.
 */
export function useLast30Days(): { dateFrom: string; dateTo: string } {
    return useMemo(() => {
        const now = new Date();

        return {
            dateFrom: format(subDays(now, 30), 'yyyy-MM-dd'),
            dateTo: format(now, 'yyyy-MM-dd'),
        };
    }, []);
}
