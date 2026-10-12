export function formatEuros(amount: number, decimals = 2): string {
    const sign = amount < 0 ? '−' : '';
    const formatted = Math.abs(amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return `${sign}€${formatted}`;
}
