import ts from 'typescript';
import type { ValidationContext } from './validation-context';
import { IMPORTABLE_MODULES, WIDGET_MODULES } from './widget-modules';

/**
 * Imports are named values from the allowed modules and nothing else: no
 * default or namespace imports, no type-only imports (an erased import would
 * leave the global of the same name), no side-effect imports and no import
 * attributes (Bun macros are `with { type: 'macro' }`). Each imported binding
 * is recorded with the props its component accepts.
 */
export function checkImport(
    context: ValidationContext,
    declaration: ts.ImportDeclaration,
): void {
    const specifier = declaration.moduleSpecifier;
    const module = ts.isStringLiteral(specifier) ? specifier.text : '';
    const rules = Object.hasOwn(WIDGET_MODULES, module)
        ? WIDGET_MODULES[module]
        : null;
    const clause = declaration.importClause;

    if (!rules) {
        context.report(
            declaration,
            'import',
            `Widgets cannot import "${module}". Allowed modules: ${IMPORTABLE_MODULES}.`,
        );

        return;
    }

    if (declaration.attributes) {
        context.report(
            declaration.attributes,
            'import',
            'Import attributes (`with { … }`) are not allowed in widgets.',
        );
    }

    if (!clause || clause.isTypeOnly) {
        context.report(
            declaration,
            'import',
            `Import named values from "${module}", e.g. \`import { useMemo } from 'react'\`. Side-effect and type-only imports are not allowed.`,
        );

        return;
    }

    if (clause.name) {
        context.report(
            clause.name,
            'import',
            `Default imports are not allowed. Import names, e.g. \`import { useState } from '${module}'\`.`,
        );
    }

    const bindings = clause.namedBindings;

    if (bindings && ts.isNamespaceImport(bindings)) {
        context.report(
            bindings,
            'import',
            `\`import * as\` is not allowed. Import each name you use from "${module}".`,
        );

        return;
    }

    for (const element of bindings?.elements ?? []) {
        checkImportSpecifier(context, module, element);
    }
}

function checkImportSpecifier(
    context: ValidationContext,
    module: string,
    element: ts.ImportSpecifier,
): void {
    const rules = WIDGET_MODULES[module];
    const name = (element.propertyName ?? element.name).text;

    if (element.isTypeOnly) {
        context.report(
            element,
            'import',
            `Type-only imports are not allowed; drop \`type\` from "${name}" or remove it.`,
        );

        return;
    }

    if (!rules.exports(name, context.options.isLucideIconName)) {
        context.report(element, 'import', rules.unknownExport(name));

        return;
    }

    const symbol = context.checker.getSymbolAtLocation(element.name);

    if (symbol) {
        context.imports.set(symbol, {
            module,
            name,
            props: rules.propsOf(name),
        });
    }
}
