/**
 * Every money value is stored as an integer in hundredths of its currency's
 * unit (cents), whatever the currency: the server and every importer agree on
 * that scale.
 */
const MINOR_UNITS_PER_MAJOR = 100;

/**
 * A major-unit amount as the integer the server stores, e.g. 3.99 to 399. The
 * currency is part of the signature so callers say which money they mean; this
 * app stores every currency at the same scale.
 */
export function toMinorUnits(
    majorUnits: number,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars -- same scale for every currency
    currencyCode: string,
): number {
    return Math.round(majorUnits * MINOR_UNITS_PER_MAJOR);
}

/** A stored integer as major units, e.g. 399 to 3.99, at the same scale for every currency. */
export function toMajorUnits(
    valueInMinorUnits: number,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars -- same scale for every currency
    currencyCode: string,
): number {
    return valueInMinorUnits / MINOR_UNITS_PER_MAJOR;
}

/**
 * The only shape Intl.NumberFormat takes as a currency: it throws a RangeError
 * on anything else, whether or not it knows the code. The server refuses other
 * shapes now, but rows stored before that keep theirs.
 */
const CURRENCY_CODE_SHAPE = /^[A-Za-z]{3}$/;

export function formatCurrency(
    valueInCents: number,
    currencyCode = 'USD',
    locale = 'en-US',
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
): string {
    const amount = valueInCents / 100;
    const isCurrency = CURRENCY_CODE_SHAPE.test(currencyCode);
    const formatted = new Intl.NumberFormat(locale, {
        ...(isCurrency ? { style: 'currency', currency: currencyCode } : {}),
        minimumFractionDigits,
        maximumFractionDigits,
        useGrouping: 'always',
    }).format(amount);

    // One bad row must not take a whole page down: the number, then the code.
    return (isCurrency ? formatted : `${formatted} ${currencyCode}`)
        .trimEnd()
        .replace(/\s/g, '\u202F');
}

/** A formatted amount with its figures hidden, as privacy mode shows it. */
export function maskDigits(formatted: string): string {
    return formatted.replace(/\d/g, '*');
}

export function getCurrencySymbol(currencyCode: string): string {
    const symbols: Record<string, string> = {
        USD: '$',
        EUR: '€',
        GBP: '£',
        JPY: '¥',
        NZD: 'NZ$',
        DOP: 'RD$',
        NGN: '₦',
        SEK: 'kr',
        THB: '฿',
        SGD: 'S$',
    };
    return symbols[currencyCode] || currencyCode;
}
