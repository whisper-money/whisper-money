import { type Locale } from './copy';

/** Euro amount the way each locale writes it: `€1,214.80` (en) or `1.214,80 €` (es). */
export function formatEuros(amount: number, locale: Locale, decimals = 2): string {
    const sign = amount < 0 ? '−' : '';
    const formatted = Math.abs(amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    if (locale === 'en') {
        return `${sign}€${formatted}`;
    }

    // es-ES separators, swapped by hand because `es-ES` leaves 4-digit numbers ungrouped.
    const spanish = formatted.replace(/[,.]/g, (separator) => (separator === ',' ? '.' : ','));

    return `${sign}${spanish}\u00a0€`;
}
