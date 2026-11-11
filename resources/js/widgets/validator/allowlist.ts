/**
 * Closed lists the widget validator checks TSX against. Anything that is not
 * listed here is rejected: a list of what is allowed can only fall short (and
 * is fixed by extending it), while a list of what is forbidden always leaves
 * something out. The single exception is FORBIDDEN_PROPERTY_NAMES, because
 * widget data carries arbitrary keys.
 */

export const SDK_MODULE = '@whisper-money/widget-sdk';

/** Bare specifiers a compiled widget may import, and nothing else. */
export const WIDGET_EXTERNALS = [
    'react',
    'react/jsx-runtime',
    'recharts',
    'lucide-react',
    SDK_MODULE,
] as const;

export type WidgetExternal = (typeof WIDGET_EXTERNALS)[number];

/** Named exports a widget may import from `react`. */
export const REACT_EXPORTS: ReadonlySet<string> = new Set([
    'useState',
    'useMemo',
    'useEffect',
    'useCallback',
    'useReducer',
    'Fragment',
]);

/**
 * Free identifiers (not declared nor imported in the widget) that may be used
 * as values. `window`, `document`, `globalThis`, `fetch`, `eval`, `Function`,
 * timers, `Reflect`, `Proxy` and `Symbol` stay out simply by not being here.
 */
export const SAFE_GLOBALS: ReadonlySet<string> = new Set([
    'Math',
    'Number',
    'String',
    'Boolean',
    'Date',
    'Intl',
    'JSON',
    'Map',
    'Set',
    'parseInt',
    'parseFloat',
    'isNaN',
    'isFinite',
    'Infinity',
    'NaN',
    'undefined',
]);

/**
 * Globals that may only be used through these static methods. The rest of
 * `Object` (`getPrototypeOf`, `getOwnPropertyDescriptor`, `defineProperty`…)
 * reaches prototypes without ever writing `constructor`.
 */
export const SAFE_STATIC_METHODS: ReadonlyMap<
    string,
    ReadonlySet<string>
> = new Map([
    ['Array', new Set(['isArray', 'from'])],
    ['Object', new Set(['keys', 'values', 'entries', 'fromEntries'])],
]);

/**
 * Property names that can never be read, written, destructured or used as a
 * literal key: they lead to prototypes, `Function` or the DOM. `$$typeof`,
 * `_owner` and `_store` are React element internals: with them a widget could
 * forge an element (`{ $$typeof, type: 'iframe', props }`) that JSX checks
 * never saw. The SDK's `get()` rejects them too, except `target`
 * (GET_FORBIDDEN_KEYS in sdk-spec.ts).
 */
export const FORBIDDEN_PROPERTY_NAMES: ReadonlySet<string> = new Set([
    'constructor',
    '__proto__',
    'prototype',
    '__defineGetter__',
    '__defineSetter__',
    '__lookupGetter__',
    '__lookupSetter__',
    'ownerDocument',
    'defaultView',
    'view',
    'getRootNode',
    'target',
    'currentTarget',
    'nativeEvent',
    'parentNode',
    '$$typeof',
    '_owner',
    '_store',
]);

export const HTML_TAGS: ReadonlySet<string> = new Set([
    'div',
    'span',
    'p',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'ul',
    'ol',
    'li',
    'dl',
    'dt',
    'dd',
    'table',
    'thead',
    'tbody',
    'tfoot',
    'tr',
    'th',
    'td',
    'strong',
    'em',
    'small',
    'br',
    'hr',
    'button',
]);

export const SVG_TAGS: ReadonlySet<string> = new Set([
    'svg',
    'g',
    'path',
    'rect',
    'circle',
    'line',
    'polyline',
    'polygon',
    'text',
    'tspan',
    'defs',
    'linearGradient',
    'stop',
]);

/** Attributes every allowed native tag accepts, besides `aria-*`. */
export const COMMON_ATTRIBUTES: ReadonlySet<string> = new Set([
    'key',
    'className',
    'style',
    'title',
    'role',
]);

/** Extra attributes for HTML tags. */
export const HTML_ATTRIBUTES: ReadonlySet<string> = new Set([
    'colSpan',
    'rowSpan',
    'disabled',
    'type',
]);

/** Geometry and presentation attributes for SVG tags. */
export const SVG_ATTRIBUTES: ReadonlySet<string> = new Set([
    'd',
    'x',
    'y',
    'x1',
    'y1',
    'x2',
    'y2',
    'cx',
    'cy',
    'r',
    'rx',
    'ry',
    'width',
    'height',
    'viewBox',
    'points',
    'fill',
    'fillOpacity',
    'stroke',
    'strokeWidth',
    'strokeOpacity',
    'strokeDasharray',
    'strokeLinecap',
    'strokeLinejoin',
    'opacity',
    'offset',
    'stopColor',
    'stopOpacity',
    'transform',
    'textAnchor',
    'id',
]);

/**
 * Event props on native tags. Their value must be a function with no
 * parameters, so the handler never sees the event (`event.view` is `window`).
 */
export const NATIVE_HANDLERS: ReadonlySet<string> = new Set([
    'onClick',
    'onDoubleClick',
    'onMouseEnter',
    'onMouseLeave',
    'onPointerEnter',
    'onPointerLeave',
    'onFocus',
    'onBlur',
]);

/**
 * CSS properties allowed as keys of a `style` object (and of the style props
 * of imported components). None of them accepts `url()`, so a dynamic value
 * coming from a bank description cannot load anything.
 */
export const STYLE_PROPERTIES: ReadonlySet<string> = new Set([
    'width',
    'height',
    'minWidth',
    'minHeight',
    'maxWidth',
    'maxHeight',
    'color',
    'backgroundColor',
    'borderColor',
    'borderWidth',
    'borderRadius',
    'opacity',
    'transform',
    'transformOrigin',
    'padding',
    'paddingTop',
    'paddingRight',
    'paddingBottom',
    'paddingLeft',
    'margin',
    'marginTop',
    'marginRight',
    'marginBottom',
    'marginLeft',
    'gap',
    'flex',
    'flexGrow',
    'flexShrink',
    'flexBasis',
    'gridColumn',
    'gridRow',
    'top',
    'right',
    'bottom',
    'left',
    'fontSize',
    'fontWeight',
    'lineHeight',
    'letterSpacing',
    'textAlign',
    'whiteSpace',
    'boxShadow',
    'outline',
]);

/** A literal `url(#id)`: the only `url()` allowed, in `fill` and `stroke`. */
const LOCAL_URL_REFERENCE = /^url\(#[A-Za-z][\w-]*\)$/;

/**
 * Whether a string holds a `url()` other than `url(#id)`, which could load an
 * external resource. The validator applies it to literals and the widget JSX
 * runtime to `fill`/`stroke` values that come from data.
 */
export function hasExternalUrl(text: string): boolean {
    return /url\s*\(/i.test(text) && !LOCAL_URL_REFERENCE.test(text);
}
