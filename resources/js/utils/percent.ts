interface FormatPercentOptions {
    /** Digits after the decimal separator, always shown: `5` reads `5.0%`. */
    fractionDigits?: number;
    /** Prefix a `+` to positive values, as trends do. Zero never gets a sign. */
    signed?: boolean;
}

/**
 * A percentage written the way the reader's locale writes numbers, so it sits
 * next to amounts formatted by `useFormatAmount()` without switching
 * conventions mid-sentence: `23,7 %` beside `1.234,56 €` for `es-ES`, `23.7%`
 * beside `$1,234.56` for `en-US`.
 *
 * Takes the value already in percent (`23.7` for 23.7%), which is what the
 * server and `shareOf()` hand around, not the `0.237` ratio Intl expects.
 */
export function formatPercent(
    value: number,
    locale: string,
    { fractionDigits = 1, signed = false }: FormatPercentOptions = {},
): string {
    return new Intl.NumberFormat(locale, {
        style: 'percent',
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
        signDisplay: signed ? 'exceptZero' : 'auto',
    }).format(value / 100);
}
