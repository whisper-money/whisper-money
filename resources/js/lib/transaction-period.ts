import {
    matchingQuickPeriod,
    type QuickPeriod,
    quickPeriodRange,
} from '@/lib/cashflow-block';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import {
    addDays,
    addMonths,
    addQuarters,
    addYears,
    differenceInCalendarDays,
    getQuarter,
} from 'date-fns';

export interface DateRange {
    from: Date;
    to: Date;
}

const STEP: Record<QuickPeriod, (date: Date, amount: number) => Date> = {
    month: addMonths,
    quarter: addQuarters,
    year: addYears,
};

/**
 * The range one step before (-1) or after (1) the date filter. A whole
 * month, quarter or year steps to the next one; any other range moves by its
 * own length. With no date filter both arrows land on the current month;
 * with only one end set there is nothing to step by, so it is null.
 */
export function stepPeriod(
    from: Date | null,
    to: Date | null,
    direction: -1 | 1,
    today: Date = new Date(),
): DateRange | null {
    if (!from && !to) {
        return quickPeriodRange('month', today);
    }

    if (!from || !to) {
        return null;
    }

    const unit = matchingQuickPeriod(from, to);
    if (unit) {
        return quickPeriodRange(unit, STEP[unit](from, direction));
    }

    const length = differenceInCalendarDays(to, from) + 1;

    return {
        from: addDays(from, direction * length),
        to: addDays(to, direction * length),
    };
}

/**
 * How the period stepper names the date filter: "October 2026", "Q4 2026",
 * "2026", a range, or "All dates" without one.
 */
export function periodLabel(
    from: Date | null,
    to: Date | null,
    locale: string,
): string {
    const date = (value: Date) => formatDate(value, 'MMM d, yyyy', locale);

    if (from && to) {
        switch (matchingQuickPeriod(from, to)) {
            case 'month':
                return formatMonthYear(from, locale);
            case 'quarter':
                return __('Q:quarter :year', {
                    quarter: getQuarter(from),
                    year: from.getFullYear(),
                });
            case 'year':
                return String(from.getFullYear());
            default:
                return `${date(from)} – ${date(to)}`;
        }
    }

    if (from) {
        return __('From :value', { value: date(from) });
    }

    if (to) {
        return __('Up to :value', { value: date(to) });
    }

    return __('All dates');
}
