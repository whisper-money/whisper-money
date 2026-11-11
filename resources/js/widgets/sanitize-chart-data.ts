import { hasExternalUrl } from './validator/allowlist';

/** Row keys Recharts would spread onto SVG elements that do more than paint. */
const DROPPED_KEYS: ReadonlySet<string> = new Set([
    'style',
    'href',
    'xlinkHref',
    'ref',
    'children',
    'dangerouslySetInnerHTML',
]);

const MAX_DEPTH = 8;

const cache = new WeakMap<object, unknown[]>();

/**
 * Recharts spreads every key of a data row onto the SVG element it draws and
 * wires `on*` keys up as event handlers. The validator cannot see what a row
 * holds once it has gone through `.map()` or a helper, so the rows a widget
 * hands to a chart are cleaned here, at the boundary: functions and `on*`
 * keys go (a handler would receive the event, and `event.view` is `window`),
 * as do attributes that load or run things and any string with an external
 * `url()` (`filter`, `mask`, `clipPath`, `fill`…).
 *
 * Only plain objects, arrays, dates and primitives survive. The result is
 * cached per array, so re-renders with the same data cost nothing.
 */
export function sanitizeChartData(data: unknown): unknown[] | undefined {
    if (!Array.isArray(data)) {
        return undefined;
    }

    let clean = cache.get(data);

    if (!clean) {
        clean = data.map((row) => sanitizeValue(row, 0));
        cache.set(data, clean);
    }

    return clean;
}

function sanitizeValue(value: unknown, depth: number): unknown {
    if (typeof value === 'string') {
        return hasExternalUrl(value) ? undefined : value;
    }

    if (typeof value !== 'object' || value === null) {
        return typeof value === 'function' ? undefined : value;
    }

    if (value instanceof Date) {
        return new Date(value.getTime());
    }

    if (depth >= MAX_DEPTH) {
        return undefined;
    }

    if (Array.isArray(value)) {
        return value.map((item) => sanitizeValue(item, depth + 1));
    }

    const prototype = Object.getPrototypeOf(value);

    if (prototype !== Object.prototype && prototype !== null) {
        return undefined;
    }

    return Object.fromEntries(
        Object.entries(value).flatMap(([key, item]) => {
            if (/^on[A-Z]/.test(key) || DROPPED_KEYS.has(key)) {
                return [];
            }

            const clean = sanitizeValue(item, depth + 1);

            return clean === undefined ? [] : [[key, clean]];
        }),
    );
}
