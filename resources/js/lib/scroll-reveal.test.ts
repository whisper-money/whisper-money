import { describe, expect, it } from 'vitest';
import { riseStyle, stepProgress } from './scroll-reveal';

describe('stepProgress', () => {
    it('is 0 before the step starts and 1 once it is over', () => {
        expect(stepProgress(0.1, 0.2, 0.1)).toBe(0);
        expect(stepProgress(0.5, 0.2, 0.1)).toBe(1);
    });

    it('moves linearly through the step', () => {
        expect(stepProgress(0.25, 0.2, 0.1)).toBeCloseTo(0.5);
    });
});

describe('riseStyle', () => {
    it('starts transparent and lowered, and ends in place', () => {
        expect(riseStyle(0, 10)).toEqual({
            opacity: 0,
            transform: 'translateY(10px)',
        });
        expect(riseStyle(1, 10)).toEqual({
            opacity: 1,
            transform: 'translateY(0px)',
        });
    });
});
