/**
 * Closed lists the widget validator checks TSX against. Anything that is not
 * listed here is rejected: a list of what is allowed can only fall short (and
 * is fixed by extending it), while a list of what is forbidden always leaves
 * something out. The single exception is FORBIDDEN_PROPERTY_NAMES: property
 * access stays a denylist because widget data carries arbitrary API keys,
 * which no allowlist of property names could anticipate.
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
 * Safe globals that are namespaces shared with the app: a widget may only read
 * a member of them (`Math.max(…)`, `JSON.stringify(x)`, `new Intl.NumberFormat()`),
 * never hold the namespace itself in a variable, an object, an array or an
 * argument, where a write through the copy would reach the app's own.
 */
export const NAMESPACE_GLOBALS: ReadonlySet<string> = new Set([
    'Math',
    'JSON',
    'Intl',
]);

/**
 * Safe globals that are functions shared with the app. Besides reading a
 * member of them, a widget may call them, construct them, test with them
 * (`x instanceof Date`) and pass them straight to an array callback
 * (CALLBACK_METHODS: `rows.map(Number)`, `.filter(Boolean)`), and nothing else.
 */
export const CONSTRUCTOR_GLOBALS: ReadonlySet<string> = new Set([
    'Number',
    'String',
    'Boolean',
    'Date',
    'Map',
    'Set',
]);

/** Methods a CONSTRUCTOR_GLOBALS function may be handed to as a callback. */
export const CALLBACK_METHODS: ReadonlySet<string> = new Set([
    'map',
    'filter',
    'find',
    'findIndex',
    'findLast',
    'findLastIndex',
    'some',
    'every',
    'flatMap',
    'forEach',
    'from',
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
 * never saw. `call`, `apply` and `bind` would let a widget give a method
 * every value shares (`({}).hasOwnProperty`, `[].slice`) an own property
 * the app then calls. The DOM members after them lead from a node or an event that
 * reached a widget to HTML parsing, script execution, attribute writes, the
 * rest of the tree, the document or storage; `stateNode`, `return` and
 * `_reactInternals` walk React's fibers back to host nodes. Names a finance
 * widget uses as data keys, style keys or props (`children`, `parent`, `top`,
 * `style`, `value`, `name`, `remove`…) stay out on purpose. The SDK's `get()`
 * rejects them too, except `target` (GET_FORBIDDEN_KEYS in sdk-spec.ts), and
 * both reject every name REACT_INTERNAL_PREFIX matches.
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
    'call',
    'apply',
    'bind',
    'innerHTML',
    'outerHTML',
    'insertAdjacentHTML',
    'insertAdjacentElement',
    'insertAdjacentText',
    'setHTMLUnsafe',
    'setHTML',
    'srcdoc',
    'write',
    'writeln',
    'createContextualFragment',
    'setAttribute',
    'setAttributeNS',
    'setAttributeNode',
    'attributes',
    'parentElement',
    'srcElement',
    'relatedTarget',
    'offsetParent',
    'firstChild',
    'lastChild',
    'firstElementChild',
    'lastElementChild',
    'nextSibling',
    'previousSibling',
    'nextElementSibling',
    'previousElementSibling',
    'childNodes',
    'closest',
    'querySelector',
    'querySelectorAll',
    'getElementById',
    'getElementsByClassName',
    'getElementsByTagName',
    'getElementsByName',
    'appendChild',
    'insertBefore',
    'replaceChild',
    'replaceChildren',
    'replaceWith',
    'cloneNode',
    'createElement',
    'createElementNS',
    'documentElement',
    'contentWindow',
    'contentDocument',
    'shadowRoot',
    'attachShadow',
    'cookie',
    'localStorage',
    'sessionStorage',
    'indexedDB',
    'item',
    'namedItem',
    'dispatchEvent',
    'addEventListener',
    'stateNode',
    'return',
    '_reactInternals',
]);

/**
 * The keys React puts on DOM nodes and roots (`__reactFiber$…`,
 * `__reactProps$…`, `_reactRootContainer`): own properties, so `get()` alone
 * would read them. No data key starts like this.
 */
export const REACT_INTERNAL_PREFIX = /^_{1,2}react/i;

/** Whether `name` may never be read from a widget. */
export function isForbiddenPropertyName(name: string): boolean {
    return (
        FORBIDDEN_PROPERTY_NAMES.has(name) || REACT_INTERNAL_PREFIX.test(name)
    );
}

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
