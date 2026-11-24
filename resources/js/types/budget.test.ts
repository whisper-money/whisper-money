import { describe, expect, it } from 'vitest';

import { percentUsed, usageSeverity } from './budget';

describe('percentUsed', () => {
    it('reads what was spent against the limit', () => {
        expect(percentUsed(8000, 10000)).toBe(80);
    });

    it('counts nothing used of a zero limit until something is spent', () => {
        expect(percentUsed(0, 0)).toBe(0);
        expect(percentUsed(500, 0)).toBe(100);
    });
});

describe('usageSeverity', () => {
    it('turns near at 80% and over at 100%', () => {
        expect(usageSeverity(79.9)).toBe('ok');
        expect(usageSeverity(80)).toBe('near');
        expect(usageSeverity(100)).toBe('over');
    });
});
