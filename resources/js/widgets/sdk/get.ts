import { isGetForbiddenKey } from '../validator/sdk-spec';

/**
 * `obj[key]` for a key computed at runtime, the only way a widget may read
 * one: the validator rejects `x[key]`. It reads own properties only, so no key
 * reaches a prototype (`constructor`, `__proto__`, `toString`), and it refuses
 * the forbidden names (and React's `__react…` keys) outright, even as own
 * properties of a data row.
 * Anything it will not read comes back `undefined`, never an error, so a
 * widget that probes a missing field keeps rendering.
 */
/** Taken when the SDK loads, before any widget runs. */
const { hasOwn } = Object;

export function get(object: unknown, key: unknown): unknown {
    if (typeof object !== 'object' || object === null) {
        return undefined;
    }

    if (typeof key !== 'string' && typeof key !== 'number') {
        return undefined;
    }

    const name = String(key);

    if (isGetForbiddenKey(name) || !hasOwn(object, name)) {
        return undefined;
    }

    return (object as Record<string, unknown>)[name];
}
