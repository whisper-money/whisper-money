/**
 * Every money value the server sends is an integer in its currency's minor
 * units, but how many decimals that scale represents depends on the currency:
 * EUR 2 (cents), COP 0 (no centavos in practice), BTC 8 (satoshis).
 */

/** Mirrors `CurrencyOptions::DEFAULT_DECIMALS`. */
const DEFAULT_DECIMALS = 2;

/** Currencies where CLDR is not the answer, mirroring `config/currencies.php`. */
const CLDR_OVERRIDES: Record<string, number> = {
    // Not an ISO currency, so CLDR falls back to 2 decimals for it.
    BTC: 8,
};

let decimalsByCode: Record<string, number> = {};

/**
 * Seeded from the server's `currencies.decimals` prop on boot and on every
 * navigation, the same way translations are.
 *
 * Module state is safe here — including under SSR, where one process renders
 * for many users — only because this map comes from `config/currencies.php` and
 * is identical for everyone. Should it ever become per-user, two interleaved
 * renders could read each other's scales and mis-state real amounts; it would
 * have to move into a context at that point.
 */
export function setCurrencyDecimals(
    map: Record<string, number> | undefined,
): void {
    decimalsByCode = map ?? {};
}

export function currencyDecimals(currencyCode: string): number {
    const code = currencyCode.toUpperCase();

    // The server's table wins over the browser's. Deriving the scale from the
    // browser's CLDR would let an outdated one divide a COP amount by 100 and
    // render it a hundred times too small — silently, and only for that user.
    return decimalsByCode[code] ?? CLDR_OVERRIDES[code] ?? cldrDecimals(code);
}

function cldrDecimals(currencyCode: string): number {
    try {
        return (
            new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currencyCode,
            }).resolvedOptions().maximumFractionDigits ?? DEFAULT_DECIMALS
        );
    } catch {
        // An invalid code throws rather than falling back, and a currency we
        // cannot identify is far likelier to be a two-decimal fiat than not.
        return DEFAULT_DECIMALS;
    }
}

/** How many minor units make one major unit of this currency. */
export function currencyFactor(currencyCode: string): number {
    return 10 ** currencyDecimals(currencyCode);
}

/** A stored integer as major units, e.g. 399 EUR to 3.99. */
export function toMajorUnits(
    valueInMinorUnits: number,
    currencyCode: string,
): number {
    return valueInMinorUnits / currencyFactor(currencyCode);
}

/** A major-unit amount as the integer the server stores, e.g. 3.99 to 399. */
export function toMinorUnits(majorUnits: number, currencyCode: string): number {
    return Math.round(majorUnits * currencyFactor(currencyCode));
}

/**
 * `minimumFractionDigits`/`maximumFractionDigits` override the currency's own
 * scale, for compact chart labels that deliberately drop the decimals. Leave
 * them out to render the amount at its real precision.
 */
export function formatCurrency(
    valueInMinorUnits: number,
    currencyCode = 'USD',
    locale = 'en-US',
    minimumFractionDigits?: number,
    maximumFractionDigits?: number,
): string {
    const decimals = currencyDecimals(currencyCode);

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: minimumFractionDigits ?? decimals,
        maximumFractionDigits: maximumFractionDigits ?? decimals,
        useGrouping: 'always',
    })
        .format(toMajorUnits(valueInMinorUnits, currencyCode))
        .replace(/\s/g, '\u202F');
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
