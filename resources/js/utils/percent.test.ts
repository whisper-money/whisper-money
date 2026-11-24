import { describe, expect, it } from 'vitest';

import { formatPercent } from './percent';

describe('formatPercent', () => {
    it('writes the decimal separator of the locale', () => {
        expect(formatPercent(23.7, 'en-US')).toBe('23.7%');
        expect(formatPercent(23.7, 'es-ES')).toBe('23,7 %');
        expect(formatPercent(23.7, 'fr-FR')).toMatch(/^23,7\s%$/);
    });

    it('keeps the fraction digits it was asked for', () => {
        expect(formatPercent(5, 'en-US')).toBe('5.0%');
        expect(formatPercent(10.567, 'en-US', { fractionDigits: 2 })).toBe(
            '10.57%',
        );
        expect(formatPercent(33.4, 'es-ES', { fractionDigits: 0 })).toBe(
            '33 %',
        );
    });

    it('signs positive values only when asked', () => {
        expect(formatPercent(12.5, 'es-ES', { signed: true })).toBe('+12,5 %');
        expect(formatPercent(12.5, 'es-ES')).toBe('12,5 %');
    });

    it('always shows the minus sign', () => {
        expect(formatPercent(-56, 'es-ES')).toBe('-56,0 %');
        expect(formatPercent(-56, 'es-ES', { signed: true })).toBe('-56,0 %');
    });

    it('never signs zero', () => {
        expect(formatPercent(0, 'en-US', { signed: true })).toBe('0.0%');
    });
});
