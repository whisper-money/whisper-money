import { describe, expect, it } from 'vitest';
import { guessRange } from './guess-range';

describe('guessRange', () => {
    it('keeps the dollar range for dollar-sized currencies', () => {
        expect(guessRange('USD')).toEqual({
            min: 300,
            max: 5000,
            step: 50,
            initial: 1200,
        });
        expect(guessRange('EUR')).toEqual(guessRange('USD'));
    });

    // ₹300 is a dinner, not a month: the range has to start where rupee
    // spending does.
    it('scales the range for currencies worth far less than a dollar', () => {
        expect(guessRange('INR')).toEqual({
            min: 15000,
            max: 250000,
            step: 2500,
            initial: 60000,
        });
        expect(guessRange('JPY').initial).toBe(120000);
        expect(guessRange('CLP').initial).toBe(1200000);
    });

    it('scales it down for currencies worth more than a dollar', () => {
        expect(guessRange('KWD')).toEqual({
            min: 150,
            max: 2500,
            step: 25,
            initial: 600,
        });
    });

    it('reads the code regardless of case', () => {
        expect(guessRange('inr')).toEqual(guessRange('INR'));
    });

    it('falls back to the dollar range for a currency it does not know', () => {
        expect(guessRange('XYZ')).toEqual(guessRange('USD'));
    });
});
