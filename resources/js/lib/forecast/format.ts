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

/**
 * Whether a balance falls below the account's alert threshold: its wanted
 * minimum, else zero, or nothing at all for a card or a loan without one.
 */
export function isShort(balance: number, threshold: number | null): boolean {
    return threshold !== null && balance < threshold;
}

/**
 * The tone of a balance against the account's alert threshold: red below
 * zero (or below the threshold, when that is lower), amber below the wanted
 * minimum.
 */
export function balanceTone(balance: number, threshold: number | null): string {
    if (!isShort(balance, threshold)) {
        return '';
    }

    return balance < Math.min(threshold as number, 0)
        ? 'text-destructive'
        : 'text-warning';
}

/** "A, B and C", in the reader's language. */
export function listOf(items: string[], locale: string): string {
    return new Intl.ListFormat(locale, {
        style: 'long',
        type: 'conjunction',
    }).format(items);
}
