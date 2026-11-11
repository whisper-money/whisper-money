/**
 * Print, as JSON, what a widget may use: the modules and names it may import
 * with the props of each component, the free globals, the native tags and
 * attributes, the style properties and the forbidden property names. These
 * are the validator's own closed lists, so the reference the agent reads
 * (get_widget_sdk) can never drift from what create_widget accepts.
 *
 *   bun scripts/widgets/sdk-reference.ts
 *
 * Called by App\Services\Widgets\WidgetSdkReference, which caches the result.
 * The Tailwind classes are not here: PHP reads widget-classes.json itself.
 */
import {
    COMMON_ATTRIBUTES,
    FORBIDDEN_PROPERTY_NAMES,
    HTML_ATTRIBUTES,
    HTML_TAGS,
    NATIVE_HANDLERS,
    REACT_EXPORTS,
    SAFE_GLOBALS,
    SAFE_STATIC_METHODS,
    SDK_MODULE,
    STYLE_PROPERTIES,
    SVG_ATTRIBUTES,
    SVG_TAGS,
} from '../../resources/js/widgets/validator/allowlist';
import {
    LUCIDE_ICON_PROPS,
    RECHARTS_COMPONENTS,
} from '../../resources/js/widgets/validator/component-props';
import {
    GET_FORBIDDEN_KEYS,
    SDK_COMPONENTS,
    SDK_VALUES,
} from '../../resources/js/widgets/validator/sdk-spec';

const list = (names: Iterable<string>): string[] => [...names];

const reference = {
    modules: {
        react: { exports: list(REACT_EXPORTS) },
        recharts: { components: RECHARTS_COMPONENTS },
        'lucide-react': {
            exports: 'Any lucide icon by name, e.g. Wallet, TrendingUp, ShoppingCart.',
            icon_props: LUCIDE_ICON_PROPS,
        },
        [SDK_MODULE]: {
            values: list(SDK_VALUES),
            components: SDK_COMPONENTS,
        },
    },
    globals: list(SAFE_GLOBALS),
    static_methods: Object.fromEntries(
        [...SAFE_STATIC_METHODS].map(([global, methods]) => [
            global,
            list(methods),
        ]),
    ),
    forbidden_property_names: list(FORBIDDEN_PROPERTY_NAMES),
    get_forbidden_keys: list(GET_FORBIDDEN_KEYS),
    tags: { html: list(HTML_TAGS), svg: list(SVG_TAGS) },
    attributes: {
        common: [...list(COMMON_ATTRIBUTES), 'aria-*'],
        html: list(HTML_ATTRIBUTES),
        svg: list(SVG_ATTRIBUTES),
        handlers: list(NATIVE_HANDLERS),
    },
    style_properties: list(STYLE_PROPERTIES),
};

process.stdout.write(JSON.stringify(reference));
