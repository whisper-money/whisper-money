import ts from 'typescript';
import {
    createSingleFileProgram,
    hasModifier,
    isNamedDeclaration,
    isTypeOnlyNode,
    unwrap,
    WIDGET_FILE_NAME,
} from './ast-helpers';
import { WIDGET_CLASS_NAMES } from './class-names';
import { checkImport } from './import-rules';
import { checkJsxChild, checkJsxElement } from './jsx-rules';
import {
    checkBinaryExpression,
    checkBindingElement,
    checkDeclaration,
    checkElementAccess,
    checkLoop,
    checkPropertyAccess,
    checkReference,
    checkShorthandProperty,
} from './reference-rules';
import {
    ValidationContext,
    type WidgetValidationError,
    type WidgetValidatorOptions,
} from './validation-context';
import { isLucideIconName } from './widget-modules';

export type { WidgetValidationError, WidgetValidatorOptions };

/** Widgets are small components; anything bigger is not a widget. */
export const MAX_WIDGET_SOURCE_LENGTH = 60_000;

/**
 * How deep the syntax tree may nest. Real widgets stay around 20; Bun's
 * bundler crashes the process (SIGILL, nothing to catch) somewhere past 1000
 * nested JSX elements, and recursive walks overflow the stack long before
 * the size limit.
 */
export const MAX_NESTING_DEPTH = 100;

const TOO_COMPLEX: WidgetValidationError = {
    line: 1,
    column: 1,
    code: 'too-complex',
    message: `The widget nests too deeply (more than ${MAX_NESTING_DEPTH} levels). Flatten it: extract helpers and components instead of nesting expressions and elements.`,
};

const DEFAULT_OPTIONS: WidgetValidatorOptions = {
    classNames: WIDGET_CLASS_NAMES,
    isLucideIconName,
};

type Rule = (context: ValidationContext, node: never) => void;

/**
 * The rule for each kind of node. A rule owns the walk below its node: it
 * calls `context.visit` (or `visitChildren`) for whatever it does not check
 * itself. Nodes without a rule are walked child by child.
 */
const RULES: Partial<Record<ts.SyntaxKind, Rule>> = {
    [ts.SyntaxKind.Identifier]: checkReference,
    [ts.SyntaxKind.PropertyAccessExpression]: checkPropertyAccess,
    [ts.SyntaxKind.ElementAccessExpression]: checkElementAccess,
    [ts.SyntaxKind.ShorthandPropertyAssignment]: checkShorthandProperty,
    [ts.SyntaxKind.BindingElement]: checkBindingElement,
    [ts.SyntaxKind.BinaryExpression]: checkBinaryExpression,
    [ts.SyntaxKind.ForOfStatement]: checkLoop,
    [ts.SyntaxKind.ForInStatement]: checkLoop,
    [ts.SyntaxKind.JsxElement]: checkJsxElement,
    [ts.SyntaxKind.JsxSelfClosingElement]: checkJsxElement,
    [ts.SyntaxKind.JsxExpression]: checkJsxChild,
    [ts.SyntaxKind.LabeledStatement]: (context, node: ts.LabeledStatement) =>
        context.visit(node.statement),
    [ts.SyntaxKind.BreakStatement]: () => {},
    [ts.SyntaxKind.ContinueStatement]: () => {},
};

/** Syntax widgets never need, by node kind. */
const FORBIDDEN_SYNTAX: Partial<
    Record<ts.SyntaxKind, [code: string, message: string]>
> = {
    [ts.SyntaxKind.ThisKeyword]: [
        'this',
        '`this` is not available: widgets are plain functions.',
    ],
    [ts.SyntaxKind.ClassDeclaration]: [
        'class',
        'Classes are not allowed: write the widget and its helpers as functions.',
    ],
    [ts.SyntaxKind.ClassExpression]: [
        'class',
        'Classes are not allowed: write the widget and its helpers as functions.',
    ],
    [ts.SyntaxKind.MetaProperty]: [
        'meta-property',
        '`import.meta` and `new.target` are not available in widgets.',
    ],
    [ts.SyntaxKind.ImportEqualsDeclaration]: [
        'syntax',
        '`import =` is not allowed. Use named imports.',
    ],
    [ts.SyntaxKind.ModuleDeclaration]: [
        'syntax',
        'Namespaces are not allowed. Use plain objects.',
    ],
    [ts.SyntaxKind.EnumDeclaration]: [
        'syntax',
        'Enums are not allowed. Use plain objects.',
    ],
    [ts.SyntaxKind.WithStatement]: ['syntax', '`with` is not allowed.'],
    [ts.SyntaxKind.Decorator]: ['syntax', 'Decorators are not allowed.'],
};

/**
 * Validate a widget's TSX against the closed lists. Returns every problem
 * found, each with what the widget can use instead; an empty list means the
 * source may be compiled.
 */
export function validateWidget(
    source: string,
    options: WidgetValidatorOptions = DEFAULT_OPTIONS,
): WidgetValidationError[] {
    if (source.length > MAX_WIDGET_SOURCE_LENGTH) {
        return [
            {
                line: 1,
                column: 1,
                code: 'too-large',
                message: `The widget source is ${source.length} characters long; the limit is ${MAX_WIDGET_SOURCE_LENGTH}. Split it into smaller helpers or drop unused code.`,
            },
        ];
    }

    try {
        return new WidgetValidator(source, options).run();
    } catch (error) {
        // The TypeScript parser itself recurses, and overflows on deep input.
        if (error instanceof RangeError) {
            return [TOO_COMPLEX];
        }

        throw error;
    }
}

/** The depth of the syntax tree, walked without recursion. */
function nestingDepth(root: ts.Node): number {
    let max = 0;
    const pending: [ts.Node, number][] = [[root, 0]];

    for (let next = pending.pop(); next; next = pending.pop()) {
        const [node, depth] = next;

        max = Math.max(max, depth);
        ts.forEachChild(node, (child) => {
            pending.push([child, depth + 1]);
        });
    }

    return max;
}

class WidgetValidator {
    private readonly program: ts.Program;
    private readonly context: ValidationContext;
    private defaultExports = 0;

    constructor(source: string, options: WidgetValidatorOptions) {
        const sourceFile = ts.createSourceFile(
            WIDGET_FILE_NAME,
            source,
            ts.ScriptTarget.ESNext,
            true,
            ts.ScriptKind.TSX,
        );

        this.program = createSingleFileProgram(sourceFile);
        this.context = new ValidationContext(
            sourceFile,
            this.program.getTypeChecker(),
            options,
            (node) => this.visit(node),
        );
    }

    run(): WidgetValidationError[] {
        const { sourceFile, errors } = this.context;
        const syntaxErrors = this.program.getSyntacticDiagnostics(sourceFile);

        for (const diagnostic of syntaxErrors) {
            this.context.reportAt(
                diagnostic.start ?? 0,
                'syntax',
                ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'),
            );
        }

        if (syntaxErrors.length > 0) {
            return errors;
        }

        if (nestingDepth(sourceFile) > MAX_NESTING_DEPTH) {
            return [TOO_COMPLEX];
        }

        this.checkPragmas();
        sourceFile.statements.forEach((statement) =>
            this.checkTopLevelStatement(statement),
        );

        if (this.defaultExports !== 1) {
            this.context.reportAt(
                0,
                'default-export',
                'A widget must have exactly one default export: the component, e.g. `export default function Widget() { … }`.',
            );
        }

        return errors.sort((a, b) => a.line - b.line || a.column - b.column);
    }

    private visit(node: ts.Node): void {
        if (isTypeOnlyNode(node) || this.reportForbiddenSyntax(node)) {
            return;
        }

        const rule = RULES[node.kind] as
            | ((context: ValidationContext, node: ts.Node) => void)
            | undefined;

        if (rule) {
            rule(this.context, node);
        } else if (isNamedDeclaration(node)) {
            checkDeclaration(this.context, node);
        } else {
            this.context.visitChildren(node);
        }
    }

    /**
     * Bun honours `@jsxImportSource` and friends in comments, which would make
     * the compiled module import a JSX runtime of the widget's choosing.
     */
    private checkPragmas(): void {
        const scanner = ts.createScanner(
            ts.ScriptTarget.ESNext,
            false,
            ts.LanguageVariant.JSX,
            this.context.sourceFile.text,
        );

        for (
            let token = scanner.scan();
            token !== ts.SyntaxKind.EndOfFileToken;
            token = scanner.scan()
        ) {
            const isComment =
                token === ts.SyntaxKind.SingleLineCommentTrivia ||
                token === ts.SyntaxKind.MultiLineCommentTrivia;

            if (isComment && /@jsx|@bun/i.test(scanner.getTokenText())) {
                this.context.reportAt(
                    scanner.getTokenStart(),
                    'pragma',
                    'Compiler pragmas (`@jsx…`, `@bun`) are not allowed in widget comments. Remove the comment.',
                );
            }
        }
    }

    private checkTopLevelStatement(statement: ts.Statement): void {
        if (ts.isImportDeclaration(statement)) {
            checkImport(this.context, statement);
        } else if (ts.isExportAssignment(statement)) {
            this.checkExportAssignment(statement);
        } else if (ts.isExportDeclaration(statement)) {
            this.context.report(
                statement,
                'export',
                'Widgets only export their component, with `export default`. Remove this export.',
            );
        } else {
            this.checkExportModifiers(statement);
            this.visit(statement);
        }
    }

    private checkExportModifiers(statement: ts.Statement): void {
        if (hasModifier(statement, ts.SyntaxKind.DefaultKeyword)) {
            this.defaultExports++;
        } else if (hasModifier(statement, ts.SyntaxKind.ExportKeyword)) {
            this.context.report(
                statement,
                'export',
                'Widgets only export their component, with `export default`. Drop `export` here.',
            );
        }
    }

    private checkExportAssignment(statement: ts.ExportAssignment): void {
        if (statement.isExportEquals) {
            this.context.report(
                statement,
                'export',
                'Use `export default` for the widget component, not `export =`.',
            );

            return;
        }

        this.defaultExports++;

        const expression = unwrap(statement.expression);

        if (
            !ts.isFunctionLike(expression) &&
            !this.context.isFunctionValue(expression)
        ) {
            this.context.report(
                statement,
                'default-export',
                'The default export must be the widget component: a function declared in this file.',
            );
        }

        this.visit(statement.expression);
    }

    /** Reports syntax widgets never need. Returns true when it reported. */
    private reportForbiddenSyntax(node: ts.Node): boolean {
        const problem =
            FORBIDDEN_SYNTAX[node.kind] ??
            (ts.isCallExpression(node) &&
            node.expression.kind === ts.SyntaxKind.ImportKeyword
                ? [
                      'import',
                      'Dynamic `import()` is not allowed. Data comes from useWidgetData().',
                  ]
                : null) ??
            (hasModifier(node, ts.SyntaxKind.DeclareKeyword)
                ? [
                      'declare',
                      '`declare` is not allowed: it names something that does not exist in the widget.',
                  ]
                : null) ??
            (ts.isFunctionDeclaration(node) && !node.body
                ? [
                      'declare',
                      'Function declarations need a body; overload signatures are not allowed.',
                  ]
                : null);

        if (problem) {
            this.context.report(node, problem[0], problem[1]);
        }

        return problem !== null;
    }
}
