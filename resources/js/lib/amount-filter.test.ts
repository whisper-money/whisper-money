import { describe, expect, it } from 'vitest';
import {
    fromSignedAmountRange,
    toSignedAmountRange,
    withAmountBound,
    withAmountDirection,
} from './amount-filter';

describe('toSignedAmountRange', () => {
    it('turns an Expense 0 to 30 into the negative range', () => {
        expect(
            toSignedAmountRange({ direction: 'expense', min: 0, max: 30 }),
        ).toEqual({ amountMin: -30, amountMax: 0 });
    });

    it('keeps an Income range as typed', () => {
        expect(
            toSignedAmountRange({ direction: 'income', min: 10, max: 30 }),
        ).toEqual({ amountMin: 10, amountMax: 30 });
    });

    it('pins the missing bound to zero so the range stays on its side', () => {
        expect(
            toSignedAmountRange({ direction: 'expense', min: 10, max: null }),
        ).toEqual({ amountMin: null, amountMax: -10 });
        expect(
            toSignedAmountRange({ direction: 'expense', min: null, max: 30 }),
        ).toEqual({ amountMin: -30, amountMax: 0 });
        expect(
            toSignedAmountRange({ direction: 'income', min: null, max: 30 }),
        ).toEqual({ amountMin: 0, amountMax: 30 });
        expect(
            toSignedAmountRange({ direction: 'income', min: 10, max: null }),
        ).toEqual({ amountMin: 10, amountMax: null });
    });

    it('reads a typed minus sign as a size, never as -0', () => {
        const range = toSignedAmountRange({
            direction: 'expense',
            min: -0,
            max: -30,
        });

        expect(range).toEqual({ amountMin: -30, amountMax: 0 });
        expect(Object.is(range.amountMax, -0)).toBe(false);
    });

    it('applies no filter when both bounds are empty', () => {
        expect(
            toSignedAmountRange({ direction: 'expense', min: null, max: null }),
        ).toEqual({ amountMin: null, amountMax: null });
    });

    it('passes a signed range through untouched', () => {
        expect(
            toSignedAmountRange({ direction: null, min: -50, max: 30 }),
        ).toEqual({ amountMin: -50, amountMax: 30 });
    });
});

describe('fromSignedAmountRange', () => {
    it('reads a negative range as an Expense', () => {
        expect(
            fromSignedAmountRange({ amountMin: -30, amountMax: 0 }, 'income'),
        ).toEqual({ direction: 'expense', min: 0, max: 30 });
        expect(
            fromSignedAmountRange(
                { amountMin: null, amountMax: -10 },
                'income',
            ),
        ).toEqual({ direction: 'expense', min: 10, max: null });
    });

    it('reads a positive range as Income', () => {
        expect(
            fromSignedAmountRange({ amountMin: 10, amountMax: 30 }, 'expense'),
        ).toEqual({ direction: 'income', min: 10, max: 30 });
        expect(
            fromSignedAmountRange(
                { amountMin: 50, amountMax: null },
                'expense',
            ),
        ).toEqual({ direction: 'income', min: 50, max: null });
    });

    it('takes the fallback side for an empty range', () => {
        expect(
            fromSignedAmountRange(
                { amountMin: null, amountMax: null },
                'expense',
            ),
        ).toEqual({ direction: 'expense', min: null, max: null });
    });

    it('keeps a range that spans both sides as signed', () => {
        expect(
            fromSignedAmountRange({ amountMin: -50, amountMax: 30 }, 'expense'),
        ).toEqual({ direction: null, min: -50, max: 30 });
        expect(
            fromSignedAmountRange(
                { amountMin: null, amountMax: 30 },
                'expense',
            ),
        ).toEqual({ direction: null, min: null, max: 30 });
    });

    it('round-trips what the toggle produces', () => {
        for (const input of [
            { direction: 'expense' as const, min: 5, max: 30 },
            { direction: 'income' as const, min: 5, max: 30 },
            { direction: 'expense' as const, min: 10, max: null },
            { direction: 'income' as const, min: 10, max: null },
        ]) {
            expect(
                fromSignedAmountRange(toSignedAmountRange(input), 'expense'),
            ).toEqual(input);
        }
    });
});

describe('withAmountDirection', () => {
    it('only switches the side of a range that already has one', () => {
        expect(
            withAmountDirection(
                { direction: 'expense', min: 30, max: 10 },
                'income',
            ),
        ).toEqual({ direction: 'income', min: 30, max: 10 });
    });

    it('turns a signed range into sizes, smallest first', () => {
        expect(
            withAmountDirection(
                { direction: null, min: -50, max: 30 },
                'expense',
            ),
        ).toEqual({ direction: 'expense', min: 30, max: 50 });
        expect(
            withAmountDirection(
                { direction: null, min: null, max: 30 },
                'income',
            ),
        ).toEqual({ direction: 'income', min: null, max: 30 });
    });
});

describe('withAmountBound', () => {
    it('drops a typed minus sign once a side is picked', () => {
        expect(
            withAmountBound(
                { direction: 'expense', min: null, max: null },
                'max',
                -30,
            ),
        ).toEqual({ direction: 'expense', min: null, max: 30 });
    });

    it('keeps a signed range signed while it still spans both sides', () => {
        expect(
            withAmountBound({ direction: null, min: -50, max: 30 }, 'max', 40),
        ).toEqual({ direction: null, min: -50, max: 40 });
    });

    it('gives a signed range its side once an edit leaves it on one', () => {
        expect(
            withAmountBound({ direction: null, min: -50, max: 30 }, 'max', -10),
        ).toEqual({ direction: 'expense', min: 10, max: 50 });
        expect(
            withAmountBound({ direction: null, min: -50, max: 30 }, 'min', 10),
        ).toEqual({ direction: 'income', min: 10, max: 30 });
    });
});
