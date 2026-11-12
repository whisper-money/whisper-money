import ts from 'typescript';
import { unwrap } from './ast-helpers';
import type { ComponentProps } from './component-props';

export interface WidgetValidationError {
    line: number;
    column: number;
    code: string;
    message: string;
}

export interface WidgetValidatorOptions {
    classNames: ReadonlySet<string>;
    isLucideIconName: (name: string) => boolean;
}

/** What an identifier imported by the widget refers to. */
export interface ImportedBinding {
    module: string;
    name: string;
    props: ComponentProps | null;
}

/**
 * What every rule needs while walking one widget: where to report, how to
 * keep walking, and how a name resolves (to an import, a function declared in
 * the widget, or nothing at all, which makes it a global).
 */
export class ValidationContext {
    readonly errors: WidgetValidationError[] = [];
    readonly imports = new Map<ts.Symbol, ImportedBinding>();

    constructor(
        readonly sourceFile: ts.SourceFile,
        readonly checker: ts.TypeChecker,
        readonly options: WidgetValidatorOptions,
        /** Walk a node with every rule; rules call it for the parts they do not check themselves. */
        readonly visit: (node: ts.Node) => void,
    ) {}

    visitChildren(node: ts.Node): void {
        ts.forEachChild(node, this.visit);
    }

    report(node: ts.Node, code: string, message: string): void {
        this.reportAt(node.getStart(this.sourceFile), code, message);
    }

    reportAt(position: number, code: string, message: string): void {
        const { line, character } =
            this.sourceFile.getLineAndCharacterOfPosition(position);

        this.errors.push({
            line: line + 1,
            column: character + 1,
            code,
            message,
        });
    }

    /**
     * A symbol only counts as the widget's own when it is a value declared in
     * this file: a type, an ambient declaration or a lib global with the same
     * name would compile to the real global.
     */
    isDeclaredInWidget(symbol: ts.Symbol): boolean {
        const isValue =
            (symbol.flags & (ts.SymbolFlags.Value | ts.SymbolFlags.Alias)) !==
            0;

        return (
            isValue &&
            (symbol.declarations ?? []).some(
                (declaration) =>
                    declaration.getSourceFile() === this.sourceFile,
            )
        );
    }

    importedBindingOf(node: ts.Node): ImportedBinding | null {
        if (!ts.isIdentifier(node)) {
            return null;
        }

        const symbol = this.checker.getSymbolAtLocation(node);

        return symbol ? (this.imports.get(symbol) ?? null) : null;
    }

    isImported(node: ts.Node, module: string, name: string): boolean {
        const binding = this.importedBindingOf(node);

        return binding?.module === module && binding.name === name;
    }

    /**
     * The function a name is bound to, when it can only ever be that function:
     * `function h() {}`, `const h = () => …` or
     * `const h = useCallback(() => …, [])`. A `let`, a parameter or anything
     * else could hold another function at runtime, so it resolves to null.
     */
    localFunctionOf(identifier: ts.Identifier): ts.SignatureDeclaration | null {
        const symbol = this.checker.getSymbolAtLocation(identifier);
        const declaration = symbol?.valueDeclaration;

        if (!symbol || !declaration || !this.isDeclaredInWidget(symbol)) {
            return null;
        }

        if (ts.isFunctionDeclaration(declaration) && declaration.body) {
            return declaration;
        }

        if (
            !ts.isVariableDeclaration(declaration) ||
            !declaration.initializer ||
            (ts.getCombinedNodeFlags(declaration) & ts.NodeFlags.Const) === 0
        ) {
            return null;
        }

        return this.functionOfInitializer(unwrap(declaration.initializer));
    }

    /** A function written inline, or a reference resolved to its declaration. */
    resolveFunction(expression: ts.Expression): ts.SignatureDeclaration | null {
        const node = unwrap(expression);

        if (ts.isArrowFunction(node) || ts.isFunctionExpression(node)) {
            return node;
        }

        return ts.isIdentifier(node) ? this.localFunctionOf(node) : null;
    }

    isFunctionValue(expression: ts.Expression): boolean {
        return this.resolveFunction(expression) !== null;
    }

    private functionOfInitializer(
        initializer: ts.Expression,
    ): ts.SignatureDeclaration | null {
        if (
            ts.isArrowFunction(initializer) ||
            ts.isFunctionExpression(initializer)
        ) {
            return initializer;
        }

        if (
            ts.isCallExpression(initializer) &&
            this.isImported(initializer.expression, 'react', 'useCallback') &&
            initializer.arguments[0]
        ) {
            const callback = unwrap(initializer.arguments[0]);

            return ts.isArrowFunction(callback) ||
                ts.isFunctionExpression(callback)
                ? callback
                : null;
        }

        return null;
    }
}
