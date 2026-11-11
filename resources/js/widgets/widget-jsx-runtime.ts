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

/**
 * The `react/jsx-runtime` widgets get: the app's own, with every element it
 * creates hardened.
 *
 * - Frozen, so `el.type = 'iframe'` after the validator saw a <div> fails.
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

    const props = (element as { props?: unknown }).props;

    if (typeof props === 'object' && props !== null) {
        Object.freeze(props);
    }

    return Object.freeze(
        Object.create(Object.getPrototypeOf(element), descriptors),
    );
}

function withoutExternalUrls(
    props: Record<string, unknown>,
): Record<string, unknown> {
    let safe = props;

    for (const name of COLOR_PROPS) {
        const value = props[name];

        if (typeof value === 'string' && hasExternalUrl(value)) {
            safe = { ...safe, [name]: undefined };
        }
    }

    return safe;
}
