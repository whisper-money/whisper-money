import { format, getQuarter, parse } from 'date-fns';

export type PeriodType = 'month' | 'quarter' | 'year';

/**
 * The `?period=` value for the period that holds `date`: `2026-11`, `2026-Q4`
 * or `2026`, the formats the server's `PeriodSelection` reads.
 */
export function formatPeriodParam(date: Date, periodType: PeriodType): string {
    if (periodType === 'quarter') {
        return `${format(date, 'yyyy')}-Q${getQuarter(date)}`;
    }

    if (periodType === 'year') {
        return format(date, 'yyyy');
    }

    return format(date, 'yyyy-MM');
}

/**
 * The first day of the period a `?period=` value names, or today when it is
 * missing or does not parse.
 */
export function parsePeriodParam(
    period: string | null,
    periodType: PeriodType,
): Date {
    if (!period) {
        return new Date();
    }

    try {
        if (periodType === 'quarter') {
            const match = /^(\d{4})-Q([1-4])$/.exec(period);

            if (match) {
                return new Date(Number(match[1]), (Number(match[2]) - 1) * 3);
            }
        }

        if (periodType === 'year') {
            const match = /^(\d{4})$/.exec(period);

            if (match) {
                return new Date(Number(match[1]), 0);
            }
        }

        const parsedDate = parse(period, 'yyyy-MM', new Date());

        if (!isNaN(parsedDate.getTime())) {
            return parsedDate;
        }
    } catch {
        return new Date();
    }

    return new Date();
}
