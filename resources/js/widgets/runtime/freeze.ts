/**
 * Freezing what the app hands widget code, so a widget cannot change it for
 * the app (or for the next widget) behind its back. Taken at module load,
 * before any widget runs: the statics it relies on are frozen by then
 * (harden-intrinsics.ts), but this module does not depend on that.
 */
const { freeze, isFrozen, values } = Object;
const { isArray } = Array;

function isObjectLike(value: unknown): value is object {
    return (
        (typeof value === 'object' && value !== null) ||
        typeof value === 'function'
    );
}

/**
 * One export of a module widgets link against: the value itself and every
 * function it holds, such as what `forwardRef` or `memo` wrap (`render`,
 * `type`), which React calls, or `wm.download`.
 */
export function freezeExport<T>(value: T): T {
    if (!isObjectLike(value)) {
        return value;
    }

    for (const inner of values(value)) {
        if (typeof inner === 'function') {
            freeze(inner);
        }
    }

    for (const inner of [
        (value as { render?: unknown }).render,
        (value as { type?: unknown }).type,
    ]) {
        if (typeof inner === 'function') {
            freeze(inner);
        }
    }

    return freeze(value);
}

/** A module object and every export in it. */
export function freezeModule<T extends Readonly<Record<string, unknown>>>(
    module: T,
): T {
    for (const value of values(module)) {
        freezeExport(value);
    }

    return freeze(module);
}

const deeplyFrozen = new WeakSet<object>();

/**
 * The data a widget reads, frozen all the way down: rows the app reads again
 * later (the transaction table, the next render) cannot be rewritten by the
 * widget that was handed them. Plain JSON, walked without recursion; a value
 * already frozen this way is skipped, so calling it on every render costs a
 * lookup.
 */
export function deepFreeze<T>(data: T): T {
    if (!isObjectLike(data) || deeplyFrozen.has(data)) {
        return data;
    }

    const pending: object[] = [data];
    const seen = new WeakSet<object>();

    for (let next = pending.pop(); next !== undefined; next = pending.pop()) {
        if (seen.has(next) || deeplyFrozen.has(next)) {
            continue;
        }

        seen.add(next);

        for (const child of isArray(next) ? next : values(next)) {
            if (isObjectLike(child)) {
                pending.push(child);
            }
        }

        if (!isFrozen(next)) {
            freeze(next);
        }
    }

    deeplyFrozen.add(data);

    return data;
}
