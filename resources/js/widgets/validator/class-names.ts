import widgetClasses from '../widget-classes.json';

/** Every class the precompiled widget CSS defines. */
export const WIDGET_CLASS_NAMES: ReadonlySet<string> = new Set(
    widgetClasses.classes,
);

const MAX_SUGGESTIONS = 6;

/**
 * Why a class is not available and what to use instead, or null when the
 * class exists in the precompiled widget CSS.
 */
export function describeUnknownClass(
    className: string,
    available: ReadonlySet<string>,
): string | null {
    if (available.has(className)) {
        return null;
    }

    if (/^(sm|md|lg|xl|2xl):/.test(className)) {
        return `Class "${className}" uses a viewport breakpoint, but a widget's width does not follow the viewport. Use container breakpoints instead: @sm: (~24rem), @md:, @lg:, @xl:, @3xl:, e.g. "@${className}".`;
    }

    if (className.includes('[')) {
        return `Class "${className}" uses an arbitrary value, and widgets only get the precompiled scale. Pick the closest class from the list get_widget_sdk returns${suggestionsFor(className, available)}.`;
    }

    return `Class "${className}" is not in the precompiled widget CSS${suggestionsFor(className, available)}. The full list comes with get_widget_sdk.`;
}

/**
 * Up to a handful of available classes sharing the unknown class's variant and
 * utility prefix (`dark:bg-` for `dark:bg-sky-500`), so the error points at
 * real alternatives instead of the whole list.
 */
function suggestionsFor(
    className: string,
    available: ReadonlySet<string>,
): string {
    const variantEnd = className.lastIndexOf(':') + 1;
    const utility = className.slice(variantEnd).replace(/^-/, '');
    const root = utility.split(/[-[]/)[0];
    const prefix = className.slice(0, variantEnd) + root;
    const suggestions: string[] = [];

    for (const candidate of available) {
        if (candidate.startsWith(prefix)) {
            suggestions.push(candidate);
        }

        if (suggestions.length === MAX_SUGGESTIONS) {
            break;
        }
    }

    return suggestions.length > 0
        ? `; for example: ${suggestions.join(', ')}`
        : '';
}
