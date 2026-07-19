import { describe, expect, it } from 'vitest';
import {
    isSplitValid,
    newSplitLine,
    splitRemaining,
    type SplitLineDraft,
} from './split-editor';

function lines(...amounts: number[]): SplitLineDraft[] {
    return amounts.map((amount) => newSplitLine({ amount }));
}

describe('splitRemaining', () => {
    it('is the total minus the sum of the lines', () => {
        expect(splitRemaining(-10000, lines(-2500, -7500))).toBe(0);
        expect(splitRemaining(-10000, lines(-2500))).toBe(-7500);
    });
});

describe('isSplitValid', () => {
    it('accepts at least two same-sign lines that reconcile to the total', () => {
        expect(isSplitValid(-10000, lines(-2500, -7500))).toBe(true);
    });

    it('rejects fewer than two lines', () => {
        expect(isSplitValid(-10000, lines(-10000))).toBe(false);
    });

    it('rejects lines that do not add up to the total', () => {
        expect(isSplitValid(-10000, lines(-2500, -2500))).toBe(false);
    });

    it('rejects a zero-amount line', () => {
        expect(isSplitValid(-10000, lines(-10000, 0))).toBe(false);
    });

    it('rejects a line whose sign differs from the total', () => {
        expect(isSplitValid(-10000, lines(-12500, 2500))).toBe(false);
    });
});
