import { cashflow } from '@/routes';
import { router } from '@inertiajs/react';
import { format, getQuarter } from 'date-fns';
import { useEffect } from 'react';

export type CashflowPeriodType = 'month' | 'quarter' | 'year';

function formatPeriodParam(
    currentDate: Date,
    periodType: CashflowPeriodType,
): string {
    if (periodType === 'quarter') {
        return `${format(currentDate, 'yyyy')}-Q${getQuarter(currentDate)}`;
    }

    if (periodType === 'year') {
        return format(currentDate, 'yyyy');
    }

    return format(currentDate, 'yyyy-MM');
}

/**
 * Keeps the period the user is looking at in the URL, so a refresh or a shared link
 * lands on the same one.
 *
 * A client-side visit, not a request: the server has nothing to add here. Asking it
 * re-renders every shared prop — including ~73KB gzipped of translations for the 79%
 * of users on Spanish — to be told a period we just computed. On a bare /cashflow
 * `initialPeriod` is null while the formatted param never is, so the guard is always
 * true on mount: this used to cost a second full page request on every open, and
 * another on every period change.
 *
 * Two arguments are load-bearing. The `props` callback is what makes the guard go
 * false on the next render; without it this loops forever. And `preserveState` must
 * stay true, because it defaults to false for a client-side visit and the component
 * would remount, resetting the very state that drove us here.
 */
export function usePeriodUrlSync(
    currentDate: Date,
    periodType: CashflowPeriodType,
    initialPeriod: string | null,
    initialPeriodType: string,
): void {
    useEffect(() => {
        const periodParam = formatPeriodParam(currentDate, periodType);

        if (initialPeriod === periodParam && initialPeriodType === periodType) {
            return;
        }

        router.replace({
            url: cashflow({
                query: { period: periodParam, period_type: periodType },
            }).url,
            props: (props) => ({
                ...props,
                period: periodParam,
                periodType,
            }),
            preserveScroll: true,
            preserveState: true,
        });
    }, [currentDate, initialPeriod, initialPeriodType, periodType]);
}
