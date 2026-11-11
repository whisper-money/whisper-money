import {
    currencyDecimals,
    formatCurrency,
    toMajorUnits,
} from '@/utils/currency';

export interface FormatMoneyOptions {
    /** `1,2 k €` instead of `1.234,56 €`, for chart axes and tight tiles. */
    compact?: boolean;
    /** Prefix positive amounts with `+`. */
    showSign?: boolean;
    /** Defaults to the user's own region. */
    locale?: string;
}

const FALLBACK_LOCALE = 'en-US';

let defaultLocale = FALLBACK_LOCALE;

/**
 * The region `formatMoney` writes amounts in when the widget does not say.
 * Module state, like `setCurrencyDecimals()`: widgets only ever render in the
 * browser, for the one user whose dashboard it is, so every widget on the page
 * shares it. `<UserWidget>` sets it from the user's region.
 */
export function setDefaultMoneyLocale(locale: string): void {
    defaultLocale = locale;
}

/**
 * An amount in its currency's minor units (cents for EUR, whole pesos for
 * COP, satoshis for BTC), written the way the rest of the app writes it.
 * Anything that is not a finite number comes back as an empty string rather
 * than `NaN €`, and an unknown currency code falls back to a plain number.
 */
export function formatMoney(
    minorUnits: unknown,
    currency: unknown,
    options: FormatMoneyOptions = {},
): string {
    const amount = Number(minorUnits);

    if (!Number.isFinite(amount)) {
        return '';
    }

    const code = String(currency ?? '').toUpperCase();
    const locale = options.locale ?? defaultLocale;
    const sign = options.showSign && amount > 0 ? '+' : '';

    try {
        return sign + format(amount, code, locale, options.compact === true);
    } catch {
        // A malformed locale or currency code throws a RangeError: the widget
        // still gets a readable number instead of losing its whole render.
        return (
            sign + toMajorUnits(amount, code).toFixed(currencyDecimals(code))
        );
    }
}

function format(
    amount: number,
    currency: string,
    locale: string,
    compact: boolean,
): string {
    if (!compact) {
        return formatCurrency(amount, currency, locale);
    }

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
    })
        .format(toMajorUnits(amount, currency))
        .replace(/\s/g, '\u202F');
}
