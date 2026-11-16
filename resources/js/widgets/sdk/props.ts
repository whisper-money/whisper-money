/**
 * The validator already rejects any prop outside an SDK component's closed
 * list, but what reaches it at runtime is still widget code: these read each
 * prop defensively, so a value of the wrong type never reaches the DOM.
 */

/** A class string, or nothing. */
export function classNameOf(value: unknown): string | undefined {
    return typeof value === 'string' ? value : undefined;
}

/** One of the allowed variants, or the first (the default) when it is not. */
export function oneOf<T extends string>(
    value: unknown,
    allowed: readonly [T, ...T[]],
): T {
    return allowed.includes(value as T) ? (value as T) : allowed[0];
}

/** A finite number, or the fallback. */
export function finiteNumber(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : fallback;
}
