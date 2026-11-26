/**
 * Calendar arithmetic on `YYYY-MM-DD` strings, kept to plain integers so it
 * matches app/Services/Forecast/ForecastCalendar.php to the day, with no time
 * zone in the way.
 */

export function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

export function formatDate(year: number, month: number, day: number): string {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function parseDate(date: string): [number, number, number] {
    const [year, month, day] = date.split('-').map(Number);

    return [year, month, day];
}

/**
 * The date `months` calendar months after `anchor`, on the anchor's day, or
 * on the month's last day when it is shorter.
 */
export function addMonths(anchor: string, months: number): string {
    const [year, month, day] = parseDate(anchor);
    const index = year * 12 + (month - 1) + months;
    const targetYear = Math.floor(index / 12);
    const targetMonth = (index % 12) + 1;

    return formatDate(
        targetYear,
        targetMonth,
        Math.min(day, daysInMonth(targetYear, targetMonth)),
    );
}

/** The first day of the month `offset` months after the one `date` is in. */
export function monthStart(date: string, offset = 0): string {
    return addMonths(`${date.slice(0, 8)}01`, offset);
}

/** The last day a projection of `horizonMonths` months from `startDate` covers. */
export function horizonEnd(startDate: string, horizonMonths: number): string {
    const [year, month] = parseDate(monthStart(startDate, horizonMonths - 1));

    return formatDate(year, month, daysInMonth(year, month));
}

export function monthKey(date: string): string {
    return date.slice(0, 7);
}

/** Integer division truncated toward zero, like PHP's intdiv(). */
export function intdiv(dividend: number, divisor: number): number {
    return (dividend - (dividend % divisor)) / divisor;
}
