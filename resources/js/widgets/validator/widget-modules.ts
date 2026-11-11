import * as lucide from 'lucide-react';
import { REACT_EXPORTS, SDK_MODULE } from './allowlist';
import {
    type ComponentProps,
    isLucideIcon,
    LUCIDE_ICON_PROPS,
    REACT_COMPONENTS,
    RECHARTS_COMPONENTS,
} from './component-props';
import { SDK_COMPONENTS, SDK_VALUES } from './sdk-spec';

export interface WidgetModuleRules {
    /** Whether the widget may import this name from the module. */
    exports: (
        name: string,
        isLucideIconName: (name: string) => boolean,
    ) => boolean;
    /** The closed list of props when the name is a component, else null. */
    propsOf: (name: string) => ComponentProps | null;
    /** What the agent sees when it imports a name that is not allowed. */
    unknownExport: (name: string) => string;
}

const RECHARTS_NAMES = Object.keys(RECHARTS_COMPONENTS);
const SDK_NAMES = [...SDK_VALUES, ...Object.keys(SDK_COMPONENTS)];

/**
 * The modules widget source may import, each with the names it exposes and
 * the props of its components. `react/jsx-runtime` is an external of the
 * compiled module but not importable from source: its `jsx()` would build
 * elements the JSX checks never see.
 */
export const WIDGET_MODULES: Readonly<Record<string, WidgetModuleRules>> = {
    react: {
        exports: (name) => REACT_EXPORTS.has(name),
        propsOf: (name) => REACT_COMPONENTS[name] ?? null,
        unknownExport: (name) =>
            `"${name}" cannot be imported from react. Available: ${[...REACT_EXPORTS].join(', ')}.`,
    },
    recharts: {
        exports: (name) => Object.hasOwn(RECHARTS_COMPONENTS, name),
        propsOf: (name) => RECHARTS_COMPONENTS[name] ?? null,
        unknownExport: (name) =>
            `"${name}" cannot be imported from recharts. Available: ${RECHARTS_NAMES.join(', ')}. For custom tooltips use <ChartTooltip> from ${SDK_MODULE}.`,
    },
    'lucide-react': {
        exports: (name, isLucideIconName) => isLucideIconName(name),
        propsOf: () => LUCIDE_ICON_PROPS,
        unknownExport: (name) =>
            `"${name}" is not a lucide icon. Use an icon name from lucide-react, e.g. Wallet, TrendingUp, ShoppingCart.`,
    },
    [SDK_MODULE]: {
        exports: (name) =>
            SDK_VALUES.has(name) || Object.hasOwn(SDK_COMPONENTS, name),
        propsOf: (name) => SDK_COMPONENTS[name] ?? null,
        unknownExport: (name) =>
            `"${name}" is not exported by ${SDK_MODULE}. Available: ${SDK_NAMES.join(', ')}.`,
    },
};

export const IMPORTABLE_MODULES = Object.keys(WIDGET_MODULES).join(', ');

/** Whether lucide-react exports an icon by this name. */
export function isLucideIconName(name: string): boolean {
    return isLucideIcon(name, (lucide as Record<string, unknown>)[name]);
}
