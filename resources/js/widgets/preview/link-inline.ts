import { parseImportPrelude } from '../import-prelude';
import { WIDGET_EXTERNALS } from '../validator/allowlist';

/**
 * The widget preview runs inside the chat client's MCP App iframe, whose
 * Content-Security-Policy allows inline scripts but no `data:` or `blob:`
 * module (MCP Apps spec: `script-src 'self' 'unsafe-inline'`). So it cannot
 * import the data: URLs `loadWidgetModule()` builds. It links the same
 * compiled module to the same registry another way: each import of the
 * prelude becomes a destructuring of the registry entry, the default export
 * is handed to the function at `globalThis[Symbol.for(reportKey)]`, and the result runs as an inline module script.
 * The checks are the loader's: only widget externals, only names the
 * registry has.
 */

/** Same key `registerWidgetModules()` stores the registry under. */
const REGISTRY_KEY = 'whisper-money.widget-modules';

const EXTERNALS: ReadonlySet<string> = new Set(WIDGET_EXTERNALS);

const IDENTIFIER = /^[A-Za-z_$][\w$]*$/;

/** `export{B as default};`, how Bun ends a compiled widget. */
const DEFAULT_EXPORT =
    /export\s*\{\s*([A-Za-z_$][\w$]*)\s+as\s+default\s*\}\s*;?\s*$/;

export class InlineLinkError extends Error {
    override name = 'InlineLinkError';
}

/**
 * $js as the body of an inline module script: no import, no export, the
 * component handed to `globalThis[Symbol.for(reportKey)]`.
 */
export function linkWidgetModuleInline(
    js: string,
    registry: Readonly<Record<string, Readonly<Record<string, unknown>>>>,
    reportKey: string,
): string {
    const { imports, bodyStart } = parseImportPrelude(js);
    const bindings = imports.map(({ statement, specifier }) => {
        if (!EXTERNALS.has(specifier)) {
            throw new InlineLinkError(
                `The widget imports "${specifier}", which is not a widget external.`,
            );
        }

        const names = statement
            .slice(statement.indexOf('{') + 1, statement.indexOf('}'))
            .split(',')
            .map((binding) => binding.trim())
            .filter((binding) => binding !== '')
            .map((binding) => {
                const [name, alias = name] = binding.split(/\s+as\s+/);

                if (
                    !IDENTIFIER.test(name) ||
                    !IDENTIFIER.test(alias) ||
                    !Object.hasOwn(registry[specifier] ?? {}, name)
                ) {
                    throw new InlineLinkError(
                        `"${name}" is not available from "${specifier}".`,
                    );
                }

                return `${name}: ${alias}`;
            });

        return `const { ${names.join(', ')} } = globalThis[Symbol.for(${JSON.stringify(REGISTRY_KEY)})][${JSON.stringify(specifier)}];`;
    });

    const body = js.slice(bodyStart);
    const exported = DEFAULT_EXPORT.exec(body);

    if (!exported) {
        throw new InlineLinkError(
            'The widget module has no default export component.',
        );
    }

    return [
        ...bindings,
        body.slice(0, exported.index),
        `globalThis[Symbol.for(${JSON.stringify(reportKey)})](${exported[1]});`,
    ].join('\n');
}
