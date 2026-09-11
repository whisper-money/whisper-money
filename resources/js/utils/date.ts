import { __ } from '@/utils/i18n';
import {
    isToday as dateFnsIsToday,
    isYesterday as dateFnsIsYesterday,
} from 'date-fns';

/**
 * The date-fns tokens this app uses, as the `Intl` option each one asks for.
 *
 * Only the fields are carried over, never the order: `Intl` puts them where the
 * reader's region puts them, which is the whole point. So "MMM d, yyyy" prints
 * "Sep 10, 2026" in Boston and "10 sept 2026" in Madrid off the same call — and
 * a Mexican no longer reads a British date because they read Spanish.
 */
const TOKEN_OPTIONS: Record<string, Intl.DateTimeFormatOptions> = {
    yyyy: { year: 'numeric' },
    yy: { year: '2-digit' },
    MMMM: { month: 'long' },
    MMM: { month: 'short' },
    MM: { month: '2-digit' },
    M: { month: 'numeric' },
    dd: { day: '2-digit' },
    d: { day: 'numeric' },
    EEEE: { weekday: 'long' },
    EEE: { weekday: 'short' },
};

/** Quoted literals in a pattern — date-fns' "''yy" — carry no field of their own. */
const TOKENS = /y{2,4}|M{1,4}|d{1,2}|E{3,4}/g;

function toIntlOptions(pattern: string): Intl.DateTimeFormatOptions {
    return (pattern.match(TOKENS) ?? []).reduce<Intl.DateTimeFormatOptions>(
        (options, token) => ({ ...options, ...TOKEN_OPTIONS[token] }),
        {},
    );
}

/**
 * `new Date('2026-08-01')` is UTC midnight per the ECMAScript spec, so at a
 * negative UTC offset it formats as the previous day. Appending a time makes
 * the same string parse as local midnight, which is what a date-only value
 * from the server (a budget period, a transaction date) actually means.
 */
function toLocalDate(date: Date | string | number): Date {
    if (typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date)) {
        return new Date(`${date}T00:00:00`);
    }

    return date instanceof Date ? date : new Date(date);
}

/**
 * A date written the way the reader's region writes it.
 *
 * `formatStr` still names the fields in date-fns' spelling, because that is what
 * every call site already passes; what it no longer decides is their order.
 */
export function formatDate(
    date: Date | string | number,
    formatStr: string,
    locale: string = 'en-US',
): string {
    return new Intl.DateTimeFormat(locale, toIntlOptions(formatStr)).format(
        toLocalDate(date),
    );
}

/**
 * A month from a YYYY-MM key, for chart axes: the month alone inside the
 * current year, the month and the full year outside it.
 *
 * The year is written out rather than abbreviated. date-fns wrote "Sep '25" and
 * the apostrophe was doing the work of saying "this is a year"; `Intl` has no
 * way to ask for one, and "Sep 25" beside "Sep 10" reads as a day.
 */
export function formatMonthFromYearMonth(
    yearMonth: string,
    locale: string = 'en-US',
): string {
    const [year, month] = yearMonth.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    const formatStr = isCurrentYear ? 'MMM' : 'MMM yyyy';

    return formatDate(date, formatStr, locale);
}

/**
 * Format a date to show month and year (e.g., "January 2026" or "Enero 2026")
 * Capitalizes the first letter
 */
export function formatMonthYear(date: Date, locale: string = 'en-US'): string {
    const formatted = formatDate(date, 'MMMM yyyy', locale);

    // Capitalize first letter (important for Spanish months)
    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

/**
 * Format a compact number (for charts, etc.)
 */
export function formatCompactNumber(
    value: number,
    locale: string = 'en-US',
    currency?: string,
): string {
    const options: Intl.NumberFormatOptions = {
        notation: 'compact',
        compactDisplay: 'short',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    };

    if (currency) {
        options.style = 'currency';
        options.currency = currency;
    }

    return new Intl.NumberFormat(locale, options).format(value);
}

/**
 * Format a date with day, month, and year (e.g., "Jan 23, 2025" or "23 ene 2025")
 * Capitalizes the first letter
 */
export function formatDateMedium(
    dateStr: string,
    locale: string = 'en-US',
): string {
    const date = new Date(dateStr);
    const formatted = formatDate(date, 'MMM d, yyyy', locale);

    // Capitalize first letter (important for Spanish dates)
    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

/**
 * Format a date from YYYY-MM-DD string using relative wording when close to
 * the current date. Returns "Today" or "Yesterday" when appropriate, otherwise
 * a long weekday-based label like "Monday, 3 of Jun".
 */
export function formatRelativeDate(
    dateStr: string,
    locale: string = 'en-US',
): string {
    const date = new Date(dateStr + 'T00:00:00');

    if (dateFnsIsToday(date)) {
        return __('Today');
    }

    if (dateFnsIsYesterday(date)) {
        return __('Yesterday');
    }

    const weekday = formatDate(date, 'EEEE', locale);
    const day = formatDate(date, 'd', locale);
    const month = formatDate(date, 'MMM', locale);
    const capitalizedWeekday =
        weekday.charAt(0).toUpperCase() + weekday.slice(1);
    const capitalizedMonth = month.charAt(0).toUpperCase() + month.slice(1);

    return `${capitalizedWeekday}, ${day} ${__('of')} ${capitalizedMonth}`;
}

/**
 * A day for a daily chart's X axis: "Feb 14" inside the current year, and the
 * full year alongside it outside — "Feb 14 25" would be three numbers in a row.
 */
export function formatDayFromDate(
    dateStr: string,
    locale: string = 'en-US',
): string {
    const date = new Date(dateStr + 'T00:00:00');
    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    const formatStr = isCurrentYear ? 'MMM d' : 'MMM d yyyy';

    return formatDate(date, formatStr, locale);
}

/**
 * Format a date with weekday, day, month, and year (e.g., "Thu, Jan 23, 2025" or "Jue, 23 ene 2025")
 * Capitalizes the first letter
 */
export function formatDateLong(
    dateStr: string,
    locale: string = 'en-US',
): string {
    const date = new Date(dateStr);
    const formatted = formatDate(date, 'EEE, MMM d, yyyy', locale);

    // Capitalize first letter (important for Spanish dates)
    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}
