import type { ComponentType } from 'react';
import { parseImportPrelude } from './import-prelude';
import { WIDGET_EXTERNALS, type WidgetExternal } from './validator/allowlist';

/**
 * Loading a compiled widget so it runs on the app's own React.
 *
 * Contract (what the compiler guarantees and the runtime relies on):
 *
 * 1. A compiled widget is an ES module whose only imports are named imports of
 *    WIDGET_EXTERNALS, all at the top of the module, plus a default export: the
 *    component. scripts/widgets/compile-widget.ts checks this on every build
 *    (checkCompiledOutput), so the loader never sees anything else.
 * 2. The app registers the module instances widgets get with
 *    `registerWidgetModules()`, once, before loading any widget. It lives on
 *    `globalThis` under a `Symbol.for` key because the widget's shim modules
 *    are data: URLs, outside Vite's module graph, and that is the only place
 *    both can reach. Widget code cannot: `globalThis` and `Symbol` are not in
 *    the validator's globals.
 * 3. `loadWidgetModule(js)` rewrites each external specifier to a data: URL
 *    shim that re-exports the names the widget imports from the registry
 *    (`export const useState = registry["react"]["useState"]`), then imports
 *    the rewritten module from another data: URL. Same source, same URL: the
 *    browser's module map caches a widget across mounts.
 *
 * An import map was the alternative, but import maps must be in the page
 * before the first module loads, and Vite's entry already did by the time a
 * dashboard renders.
 */

export type WidgetModuleRegistry = Readonly<
    Record<WidgetExternal, Readonly<Record<string, unknown>>>
>;

export class WidgetModuleError extends Error {
    override name = 'WidgetModuleError';
}

const REGISTRY_KEY = Symbol.for('whisper-money.widget-modules');

const EXTERNALS: ReadonlySet<string> = new Set(WIDGET_EXTERNALS);

const IDENTIFIER = /^[A-Za-z_$][\w$]*$/;

type GlobalWithRegistry = typeof globalThis & {
    [REGISTRY_KEY]?: WidgetModuleRegistry;
};

export function registerWidgetModules(registry: WidgetModuleRegistry): void {
    (globalThis as GlobalWithRegistry)[REGISTRY_KEY] = Object.freeze({
        ...registry,
    });
}

/**
 * Evaluate a compiled widget and return its component. Throws a
 * WidgetModuleError when the module does not follow the contract or imports a
 * name the registry does not have.
 */
export async function loadWidgetModule(js: string): Promise<ComponentType> {
    const registry = (globalThis as GlobalWithRegistry)[REGISTRY_KEY];

    if (!registry) {
        throw new WidgetModuleError(
            'Widget modules are not registered: call registerWidgetModules() first.',
        );
    }

    const url = toDataUrl(linkImports(js, registry));
    let module: { default?: unknown };

    try {
        module = (await import(/* @vite-ignore */ url)) as {
            default?: unknown;
        };
    } catch (error) {
        // A bare import left outside the prelude cannot resolve from a data: URL.
        throw new WidgetModuleError('The widget module failed to load.', {
            cause: error,
        });
    }

    if (typeof module.default !== 'function') {
        throw new WidgetModuleError(
            'The widget module has no default export component.',
        );
    }

    return module.default as ComponentType;
}

/** Point every import of the module's import prelude at a registry shim. */
function linkImports(js: string, registry: WidgetModuleRegistry): string {
    const { imports, bodyStart } = parseImportPrelude(js);
    const linked = imports.map(({ statement, specifier, names }) => {
        if (!EXTERNALS.has(specifier)) {
            throw new WidgetModuleError(
                `The widget imports "${specifier}", which is not a widget external.`,
            );
        }

        const shim = toDataUrl(
            shimSource(specifier as WidgetExternal, names, registry),
        );

        return statement.replace(/(["'])[^"']+\1/, JSON.stringify(shim));
    });

    return linked.join('') + js.slice(bodyStart);
}

function shimSource(
    specifier: WidgetExternal,
    names: string[],
    registry: WidgetModuleRegistry,
): string {
    const module = registry[specifier];
    const exports = names.map((name) => {
        if (!IDENTIFIER.test(name) || !module || !Object.hasOwn(module, name)) {
            throw new WidgetModuleError(
                `"${name}" is not available from "${specifier}".`,
            );
        }

        return `${name} = m[${JSON.stringify(name)}]`;
    });

    return `const m = globalThis[Symbol.for(${JSON.stringify(REGISTRY_KEY.description)})][${JSON.stringify(specifier)}];\nexport const ${exports.join(', ')};`;
}

function toDataUrl(source: string): string {
    return `data:text/javascript;charset=utf-8,${encodeURIComponent(source)}`;
}
