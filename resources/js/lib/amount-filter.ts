/**
 * The amount filter is entered as positive magnitudes plus an Expense/Income
 * direction, while the filters (and the backend) keep a signed range, because
 * expenses are stored as negative amounts. These helpers convert between both.
 */

export type AmountDirection = 'expense' | 'income';

export interface SignedAmountRange {
    amountMin: number | null;
    amountMax: number | null;
}

/**
 * What the inputs show. `direction` is null for a signed range that spans
 * both expenses and income (an old URL or saved filter such as `max 30`), which
 * the toggle cannot express: its bounds are then shown and kept as signed.
 */
export interface AmountFilterInput {
    direction: AmountDirection | null;
    min: number | null;
    max: number | null;
}

function negate(value: number): number {
    return value === 0 ? 0 : -value;
}

/**
 * Expense `[min, max]` is the signed range `[-max, -min]`; Income is
 * `[min, max]`. With a single bound, the other one is pinned to zero so the
 * range stays on its side: Expense `max 30` matches `-30..0`, not every income.
 */
export function toSignedAmountRange({
    direction,
    min,
    max,
}: AmountFilterInput): SignedAmountRange {
    if (direction === null) {
        return { amountMin: min, amountMax: max };
    }

    const low = min === null ? null : Math.abs(min);
    const high = max === null ? null : Math.abs(max);

    if (low === null && high === null) {
        return { amountMin: null, amountMax: null };
    }

    if (direction === 'income') {
        return { amountMin: low ?? 0, amountMax: high };
    }

    return {
        amountMin: high === null ? null : negate(high),
        amountMax: low === null ? 0 : negate(low),
    };
}

/**
 * The inverse of {@link toSignedAmountRange}, deriving the direction from the
 * signs. An empty range has no direction of its own and takes `fallback`.
 */
export function fromSignedAmountRange(
    { amountMin, amountMax }: SignedAmountRange,
    fallback: AmountDirection,
): AmountFilterInput {
    if (amountMin === null && amountMax === null) {
        return { direction: fallback, min: null, max: null };
    }

    if (amountMax !== null && amountMax <= 0) {
        return {
            direction: 'expense',
            min: negate(amountMax),
            max: amountMin === null ? null : negate(amountMin),
        };
    }

    if (amountMin !== null && amountMin >= 0) {
        return { direction: 'income', min: amountMin, max: amountMax };
    }

    return { direction: null, min: amountMin, max: amountMax };
}

/**
 * Picks a side. Leaving a signed range that spans both sides turns its bounds
 * into sizes, smallest first: `-50..30` as Expense is `30..50`.
 */
export function withAmountDirection(
    input: AmountFilterInput,
    direction: AmountDirection,
): AmountFilterInput {
    if (input.direction !== null) {
        return { ...input, direction };
    }

    const sizes = [input.min, input.max].map((bound) =>
        bound === null ? null : Math.abs(bound),
    );
    const [min, max] =
        sizes[0] !== null && sizes[1] !== null && sizes[0] > sizes[1]
            ? [sizes[1], sizes[0]]
            : sizes;

    return { direction, min, max };
}

/**
 * Applies a typed bound. With a side picked a typed minus sign is dropped,
 * since the side owns the sign; a signed range that an edit leaves on one
 * side gets that side.
 */
export function withAmountBound(
    input: AmountFilterInput,
    bound: 'min' | 'max',
    value: number | null,
): AmountFilterInput {
    if (input.direction !== null) {
        return {
            ...input,
            [bound]: value === null ? null : Math.abs(value),
        };
    }

    const next = { ...input, [bound]: value };
    const derived = fromSignedAmountRange(toSignedAmountRange(next), 'expense');

    return derived.direction === null ? next : derived;
}
