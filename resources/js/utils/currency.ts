export function formatCurrency(valueInCents: number, currencyCode = 'USD'): string {
    const amount = valueInCents / 100;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function getCurrencySymbol(currencyCode: string): string {
    const symbols: Record<string, string> = {
        USD: '$',
        EUR: '€',
        GBP: '£',
        JPY: '¥',
    };
    return symbols[currencyCode] || currencyCode;
}

