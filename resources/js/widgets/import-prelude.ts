export interface PreludeImport {
    /** The statement as written, e.g. `import{useState as a}from"react";`. */
    statement: string;
    specifier: string;
    /** Imported names, before any `as` alias. */
    names: string[];
}

/** `import{a as b,c}from"react";`, as Bun emits them (quotes either way). */
const IMPORT_STATEMENT =
    /\s*import\s*\{([^}]*)\}\s*from\s*(["'])([^"']+)\2\s*;?/y;

/**
 * The named imports at the very top of a compiled widget, and where the rest
 * of the module starts. The loader rewrites exactly these, and the compiler
 * checks every import of its output is one of them, so both read the module
 * the same way.
 */
export function parseImportPrelude(js: string): {
    imports: PreludeImport[];
    bodyStart: number;
} {
    const imports: PreludeImport[] = [];
    let bodyStart = 0;

    IMPORT_STATEMENT.lastIndex = 0;

    for (
        let match = IMPORT_STATEMENT.exec(js);
        match;
        match = IMPORT_STATEMENT.exec(js)
    ) {
        const [statement, bindings, , specifier] = match;

        imports.push({
            statement,
            specifier,
            names: bindings
                .split(',')
                .map((binding) => binding.trim().split(/\s+as\s+/)[0])
                .filter((name) => name !== ''),
        });
        bodyStart = IMPORT_STATEMENT.lastIndex;
    }

    return { imports, bodyStart };
}
