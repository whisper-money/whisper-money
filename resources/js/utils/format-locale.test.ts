import { describe, expect, it } from 'vitest';
import { formatLocaleOptions } from './format-locale';

describe('formatLocaleOptions', () => {
    it('names the country and shows what it does to an amount and a date', () => {
        const [mexico] = formatLocaleOptions(['es-MX'], 'MXN', 'en-US');

        expect(mexico.code).toBe('es-MX');
        expect(mexico.label).toBe('Mexico — $1,234.56 · 25/12/2026');
    });

    it('tells apart two regions that share a language', () => {
        const [spain] = formatLocaleOptions(['es-ES'], 'EUR', 'en-US');
        const [mexico] = formatLocaleOptions(['es-MX'], 'EUR', 'en-US');

        expect(spain.label).toContain('1.234,56');
        expect(mexico.label).toContain('1,234.56');
    });

    it('names the Latin American tag, which has no country of its own', () => {
        const [latam] = formatLocaleOptions(['es-419'], 'USD', 'en-US');

        expect(latam.label).toContain('Latin America');
    });

    it('writes the country names in the reader’s own language', () => {
        const [spain] = formatLocaleOptions(['es-ES'], 'EUR', 'es-ES');

        expect(spain.label).toContain('España');
    });

    it('sorts by label so a long list can be read down', () => {
        const labels = formatLocaleOptions(
            ['es-MX', 'de-DE', 'en-AU'],
            'EUR',
            'en-US',
        ).map((option) => option.code);

        expect(labels).toEqual(['en-AU', 'de-DE', 'es-MX']);
    });
});
