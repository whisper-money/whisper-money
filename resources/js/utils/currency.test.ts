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
                expect(
                    formatted,
                    `Expected thousands separator for ${cents} cents`,
                ).toContain(',');
            }
            // The formatted value must exactly match formatting the true decimal amount
            const expected = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(cents / 100);
            expect(formatted.replace(/\u202F/g, ' ')).toBe(
                expected.replace(/\s/g, ' '),
            );
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

    it('preserves two decimals for chart-style balance amounts', () => {
        expect(formatCurrency(164545, 'EUR', 'de-DE')).toBe('1.645,45\u202F€');
        expect(formatCurrency(164545, 'USD', 'en-US')).toBe('$1,645.45');
    });

    it('formats es-ES EUR 4-digit amounts with thousands separator', () => {
        // In some ICU/CLDR versions, es-ES uses useGrouping:'auto' which omits the thousands
        // separator for 4-digit numbers (1,000–9,999). We force useGrouping:'always' so that
        // 1.560,07 € is shown instead of 1560,07 €.
        const formatted = formatCurrency(156007, 'EUR', 'es-ES');
        expect(formatted).toContain('1.560');
    });

    it('formats es-ES EUR amounts between 1,000 and 9,999 with thousands separator', () => {
        const cases = [
            { cents: 156007, expectedInteger: '1.560' },
            { cents: 100001, expectedInteger: '1.000' },
            { cents: 999999, expectedInteger: '9.999' },
            { cents: 150000, expectedInteger: '1.500' },
        ];

        for (const { cents, expectedInteger } of cases) {
            const formatted = formatCurrency(cents, 'EUR', 'es-ES');
            expect(
                formatted,
                `Expected thousands separator for ${cents} cents in es-ES`,
            ).toContain(expectedInteger);
        }
    });
});
