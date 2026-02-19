import { format as dateFnsFormat } from 'date-fns';
import { es } from 'date-fns/locale';

/**
 * Get the date-fns locale object based on locale code
 */
function getDateFnsLocale(locale: string) {
    switch (locale) {
        case 'es':
            return es;
        default:
            return undefined; // Uses English by default
    }
}

/**
 * Format a date using the user's locale
 */
export function formatDate(
    date: Date | string | number,
    formatStr: string,
    locale: string = 'en-US',
): string {
    const dateObj =
        typeof date === 'string' || typeof date === 'number'
            ? new Date(date)
            : date;

    const dateFnsLocale = getDateFnsLocale(locale);

    return dateFnsFormat(dateObj, formatStr, {
        locale: dateFnsLocale,
    });
}

/**
 * Format a month from YYYY-MM string
 * Shows abbreviated month for current year, or "MMM 'YY" for other years
 */
export function formatMonthFromYearMonth(
    yearMonth: string,
    locale: string = 'en-US',
): string {
    const [year, month] = yearMonth.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    const formatStr = isCurrentYear ? 'MMM' : "MMM ''yy";

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
 * Format a date from YYYY-MM-DD string for daily chart X-axis labels
 * Shows "MMM d" (e.g., "Feb 14") for current year, or "MMM d 'YY" for other years
 */
export function formatDayFromDate(
    dateStr: string,
    locale: string = 'en-US',
): string {
    const date = new Date(dateStr + 'T00:00:00');
    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    const formatStr = isCurrentYear ? 'MMM d' : "MMM d ''yy";

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
