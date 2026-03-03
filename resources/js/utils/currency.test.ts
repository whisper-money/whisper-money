import { describe, expect, it } from 'vitest';
import { formatCurrency } from './currency';

describe('formatCurrency', () => {
    it('formats a basic USD amount', () => {
        expect(formatCurrency(1000, 'USD', 'en-US')).toBe('$10.00');
    });

    it('formats a USD amount with thousands separator', () => {
        expect(formatCurrency(1234567, 'USD', 'en-US')).toBe('$12,345.67');
    });

    it('formats a large amount without losing the thousands separator', () => {
        // This value (1234567 cents) used to produce incorrect output due to the
        // amountInCents / 100 * 100 floating-point round-trip in AmountDisplay.
        // 1234567 / 100 = 12345.67 (exact in IEEE 754)
        // But values like 12345.670000000002 caused Intl.NumberFormat to drop the separator.
        expect(formatCurrency(1234500, 'USD', 'en-US')).toBe('$12,345.00');
        expect(formatCurrency(10000000, 'USD', 'en-US')).toBe('$100,000.00');
        expect(formatCurrency(100000000, 'USD', 'en-US')).toBe('$1,000,000.00');
    });

    it('does not produce floating-point artifacts for amounts affected by the round-trip bug', () => {
        // These specific cent values trigger floating-point imprecision when divided and
        // multiplied back (i.e. n / 100 * 100 !== n exactly in IEEE 754), which previously
        // caused Intl.NumberFormat to format incorrectly.
        const problematicValues = [1234571, 9999901, 5000003, 123456789];

        for (const cents of problematicValues) {
            const formatted = formatCurrency(cents, 'USD', 'en-US');
            // The formatted string must contain a thousands separator for values >= $1,000
            if (cents >= 100000) {
                expect(formatted, `Expected thousands separator for ${cents} cents`).toContain(',');
            }
            // The formatted value must exactly match formatting the true decimal amount
            const expected = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(cents / 100);
            expect(formatted.replace(/\u202F/g, ' ')).toBe(expected.replace(/\s/g, ' '));
        }
    });

    it('formats EUR with correct symbol', () => {
        expect(formatCurrency(1099, 'EUR', 'en-US')).toBe('€10.99');
    });

    it('formats zero correctly', () => {
        expect(formatCurrency(0, 'USD', 'en-US')).toBe('$0.00');
    });

    it('formats negative amounts', () => {
        expect(formatCurrency(-5050, 'USD', 'en-US')).toBe('-$50.50');
    });

    it('respects custom fraction digits', () => {
        expect(formatCurrency(100000, 'USD', 'en-US', 0, 0)).toBe('$1,000');
    });
});
