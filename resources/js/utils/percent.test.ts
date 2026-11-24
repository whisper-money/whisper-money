import { describe, expect, it } from 'vitest';

import { formatPercent } from './percent';

/**
 * es and fr put a no-break space before `%`, and which one (U+00A0 or U+202F)
 * depends on the ICU data Node ships with, so compare with plain spaces.
 */
function format(...args: Parameters<typeof formatPercent>): string {
    return formatPercent(...args).replace(/\s/g, ' ');
}

describe('formatPercent', () => {
    it('writes the decimal separator of the locale', () => {
        expect(format(23.7, 'en-US')).toBe('23.7%');
        expect(format(23.7, 'es-ES')).toBe('23,7 %');
        expect(format(23.7, 'fr-FR')).toBe('23,7 %');
    });

    it('keeps the fraction digits it was asked for', () => {
        expect(format(5, 'en-US')).toBe('5.0%');
        expect(format(10.567, 'en-US', { fractionDigits: 2 })).toBe('10.57%');
        expect(format(33.4, 'es-ES', { fractionDigits: 0 })).toBe('33 %');
    });

    it('signs positive values only when asked', () => {
        expect(format(12.5, 'es-ES', { signed: true })).toBe('+12,5 %');
        expect(format(12.5, 'es-ES')).toBe('12,5 %');
    });

    it('always shows the minus sign', () => {
        expect(format(-56, 'es-ES')).toBe('-56,0 %');
        expect(format(-56, 'es-ES', { signed: true })).toBe('-56,0 %');
    });

    it('never signs zero', () => {
        expect(format(0, 'en-US', { signed: true })).toBe('0.0%');
    });
});
