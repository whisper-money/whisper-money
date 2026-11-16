import { hasExternalUrl } from './validator/allowlist';

type JsxFactory = (
    type: unknown,
    props: Record<string, unknown>,
    key?: unknown,
) => unknown;

export type JsxRuntime = {
    jsx: JsxFactory;
    jsxs: JsxFactory;
    Fragment: unknown;
};

const COLOR_PROPS = ['fill', 'stroke'] as const;

/** Taken when the module loads, before any widget runs. */
const { freeze, getPrototypeOf, hasOwn, isFrozen, values } = Object;
const { isArray } = Array;
const OBJECT_PROTOTYPE = Object.prototype;

/**
 * The `react/jsx-runtime` widgets get: the app's own, with every element it
 * creates hardened.
 *
 * - Frozen, so `el.type = 'iframe'` after the validator saw a <div> fails,
 *   with its props and the plain objects and arrays in them (`style`, a
 *   Recharts `tick`, `children`), so `el.props.style.background = 'url(…)'`
 *   or a handler added to `tick` after the checks fails too.
 * - `$$typeof` made non-enumerable, so `{ ...el, type: 'iframe' }` produces a
 *   plain object React refuses to render (the validator already rejects
 *   reading `$$typeof` by name, and the SDK's `get()` must too).
 * - `fill`/`stroke` strings with a `url()` that is not `url(#id)` are dropped:
 *   the validator only allows them as literals, and this covers values that
 *   come from data at runtime.
 */
export function createWidgetJsxRuntime(runtime: JsxRuntime): JsxRuntime {
    const wrap =
        (factory: JsxFactory): JsxFactory =>
        (type, props, key) =>
            hardenElement(factory(type, withoutExternalUrls(props), key));

    return {
        jsx: wrap(runtime.jsx),
        jsxs: wrap(runtime.jsxs),
        Fragment: runtime.Fragment,
    };
}

function hardenElement(element: unknown): unknown {
    if (typeof element !== 'object' || element === null) {
        return element;
    }

    const descriptors = Object.getOwnPropertyDescriptors(element);

    if (descriptors.$$typeof) {
        descriptors.$$typeof = { ...descriptors.$$typeof, enumerable: false };
    }

    freezePlainValues((element as { props?: unknown }).props);

    return Object.freeze(
        Object.create(Object.getPrototypeOf(element), descriptors),
    );
}

/**
 * Freeze a props object and every plain object or array under it, even under
 * one React already froze (it freezes `props` in development, not what they
 * hold). Elements (created, and so hardened, on their own), functions and
 * class instances such as a Date are left as they are.
 */
function freezePlainValues(root: unknown): void {
    const pending: unknown[] = [root];
    const seen = new WeakSet<object>();

    while (pending.length > 0) {
        const next = pending.pop();

        if (typeof next !== 'object' || next === null || seen.has(next)) {
            continue;
        }

        seen.add(next);

        const prototype = getPrototypeOf(next);
        const isPlain =
            isArray(next) ||
            prototype === OBJECT_PROTOTYPE ||
            prototype === null;

        if (!isPlain || hasOwn(next, '$$typeof')) {
            continue;
        }

        pending.push(...values(next));

        if (!isFrozen(next)) {
            freeze(next);
        }
    }
}

function withoutExternalUrls(
    props: Record<string, unknown>,
): Record<string, unknown> {
    let safe = props;

    for (const name of COLOR_PROPS) {
        const value = props[name];

        // Only strings and numbers are colours. Anything else is dropped: an
        // object is turned into text when the attribute is set, so its
        // `toString` could answer a `url()` this check never read.
        const isUnsafe =
            typeof value === 'string'
                ? hasExternalUrl(value)
                : value !== undefined &&
                  value !== null &&
                  typeof value !== 'number';

        if (isUnsafe) {
            safe = { ...safe, [name]: undefined };
        }
    }

    return safe;
}
