export function formatCurrency(
    valueInCents: number,
    currencyCode = 'USD',
    locale = 'en-US',
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
): string {
    const amount = valueInCents / 100;
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits,
        maximumFractionDigits,
        useGrouping: 'always',
    })
        .format(amount)
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
    };
    return symbols[currencyCode] || currencyCode;
}
