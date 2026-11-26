import {
    type ForecastLineKind,
    type ForecastLineSource,
} from '@/types/forecast';
import { formatDate, formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** A plan column's month: "nov 26". */
export function monthColumnLabel(month: string, locale: string): string {
    return formatDate(`${month}-01`, 'MMM yy', locale);
}

/** A chart bar's month: "nov". */
export function monthShortLabel(month: string, locale: string): string {
    return formatDate(`${month}-01`, 'MMM', locale);
}

/** "noviembre", as a sentence reads it. */
export function monthLongName(month: string, locale: string): string {
    return formatMonthName(month, locale);
}

/** "noviembre 2026". */
export function monthWithYear(month: string, locale: string): string {
    return formatDate(`${month}-01`, 'MMMM yyyy', locale);
}

/** The day of a `YYYY-MM-DD` date, as a number. */
export function dayOf(date: string): number {
    return Number(date.slice(8, 10));
}

export function lineKindLabel(kind: ForecastLineKind): string {
    switch (kind) {
        case 'monthly':
            return __('Recurring');
        case 'bimonthly':
            return __('Bimonthly');
        case 'quarterly':
            return __('Quarterly');
        case 'yearly':
            return __('Yearly');
        case 'once':
            return __('One-off');
        case 'transfer':
            return __('Transfer');
    }
}

function sourceLabel(source: ForecastLineSource): string {
    switch (source) {
        case 'detected':
            return __('Detected');
        case 'manual':
            return __('Added by you');
        case 'suggested':
            return __('Suggested');
    }
}

/**
 * Where a line comes from and when it lands: "Detected · day 28",
 * "Added by you · January", "Added by you · 15 Dec".
 */
export function lineOrigin(
    kind: ForecastLineKind,
    source: ForecastLineSource,
    anchorDate: string,
    locale: string,
): string {
    const when =
        kind === 'yearly'
            ? formatMonthName(anchorDate.slice(0, 7), locale)
            : kind === 'once'
              ? formatDate(anchorDate, 'd MMM', locale)
              : __('day :day', { day: dayOf(anchorDate) });

    return `${sourceLabel(source)} · ${when}`;
}
