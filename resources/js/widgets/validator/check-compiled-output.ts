import ts from 'typescript';
import { parseImportPrelude } from '../import-prelude';
import { WIDGET_EXTERNALS } from './allowlist';

export interface CompiledOutputProblem {
    code: string;
    message: string;
}

const EXTERNALS: ReadonlySet<string> = new Set(WIDGET_EXTERNALS);

/**
 * Check the JavaScript `Bun.build` produced, so what was validated is what
 * runs: it may only import the allowed externals, by name, in static imports at
 * the top of the module (the loader rewrites exactly those), and it must have a
 * default export. Returns the problems found; an empty list means it is fine.
 */
export function checkCompiledOutput(js: string): CompiledOutputProblem[] {
    const sourceFile = ts.createSourceFile(
        '/widget.js',
        js,
        ts.ScriptTarget.ESNext,
        true,
        ts.ScriptKind.JS,
    );
    const problems: CompiledOutputProblem[] = [];
    let seenCode = false;
    let hasDefaultExport = false;

    for (const statement of sourceFile.statements) {
        if (ts.isImportDeclaration(statement)) {
            problems.push(...checkImport(statement, seenCode));

            continue;
        }

        seenCode = true;

        if (ts.isExportDeclaration(statement)) {
            if (statement.moduleSpecifier) {
                problems.push({
                    code: 'output-import',
                    message:
                        'The compiled widget re-exports from another module.',
                });
            }

            hasDefaultExport ||= exportsDefault(statement);
        } else if (
            ts.isExportAssignment(statement) &&
            !statement.isExportEquals
        ) {
            hasDefaultExport = true;
        }
    }

    problems.push(...findRuntimeImports(sourceFile));
    problems.push(...checkLinkable(js, sourceFile));

    if (!hasDefaultExport) {
        problems.push({
            code: 'output-export',
            message: 'The compiled widget has no default export.',
        });
    }

    return problems;
}

function checkImport(
    statement: ts.ImportDeclaration,
    seenCode: boolean,
): CompiledOutputProblem[] {
    const specifier = (statement.moduleSpecifier as ts.StringLiteral).text;
    const bindings = statement.importClause?.namedBindings;

    if (!EXTERNALS.has(specifier)) {
        return [
            {
                code: 'output-import',
                message: `The compiled widget imports "${specifier}", which is not an allowed external.`,
            },
        ];
    }

    if (
        seenCode ||
        statement.importClause?.name ||
        !bindings ||
        !ts.isNamedImports(bindings)
    ) {
        return [
            {
                code: 'output-import',
                message: `The compiled widget imports "${specifier}" in a form the loader does not support (only named imports at the top).`,
            },
        ];
    }

    return [];
}

/**
 * The loader reads imports with parseImportPrelude, not a parser: every import
 * the AST sees must be one it reads too, or the loader would leave it bare.
 */
function checkLinkable(
    js: string,
    sourceFile: ts.SourceFile,
): CompiledOutputProblem[] {
    const parsed = sourceFile.statements
        .filter(ts.isImportDeclaration)
        .map(
            (statement) => (statement.moduleSpecifier as ts.StringLiteral).text,
        );
    const linked = parseImportPrelude(js).imports.map(
        (prelude) => prelude.specifier,
    );

    return parsed.join('\n') === linked.join('\n')
        ? []
        : [
              {
                  code: 'output-import',
                  message:
                      'The compiled widget imports in a form the loader cannot link.',
              },
          ];
}

function exportsDefault(statement: ts.ExportDeclaration): boolean {
    const clause = statement.exportClause;

    return (
        clause !== undefined &&
        ts.isNamedExports(clause) &&
        clause.elements.some((element) => element.name.text === 'default')
    );
}

/** `import()` and `require()` anywhere in the output. */
function findRuntimeImports(
    sourceFile: ts.SourceFile,
): CompiledOutputProblem[] {
    const problems: CompiledOutputProblem[] = [];
    const visit = (node: ts.Node): void => {
        if (
            ts.isCallExpression(node) &&
            (node.expression.kind === ts.SyntaxKind.ImportKeyword ||
                (ts.isIdentifier(node.expression) &&
                    node.expression.text === 'require'))
        ) {
            problems.push({
                code: 'output-import',
                message: 'The compiled widget loads a module at runtime.',
            });
        }

        ts.forEachChild(node, visit);
    };

    visit(sourceFile);

    return problems;
}
