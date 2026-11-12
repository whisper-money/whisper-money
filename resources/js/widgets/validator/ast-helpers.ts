import ts from 'typescript';

export const WIDGET_FILE_NAME = '/widget.tsx';

/**
 * A program over the widget alone, with no lib: every global the widget names
 * resolves to nothing, so the allowlist is the only way one gets through.
 */
export function createSingleFileProgram(sourceFile: ts.SourceFile): ts.Program {
    const options: ts.CompilerOptions = {
        noLib: true,
        noResolve: true,
        types: [],
        jsx: ts.JsxEmit.ReactJSX,
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.ESNext,
    };
    const host: ts.CompilerHost = {
        getSourceFile: (fileName) =>
            fileName === WIDGET_FILE_NAME ? sourceFile : undefined,
        getDefaultLibFileName: () => 'lib.d.ts',
        writeFile: () => {},
        getCurrentDirectory: () => '/',
        getCanonicalFileName: (fileName) => fileName,
        useCaseSensitiveFileNames: () => true,
        getNewLine: () => '\n',
        fileExists: (fileName) => fileName === WIDGET_FILE_NAME,
        readFile: () => undefined,
    };

    return ts.createProgram({
        rootNames: [WIDGET_FILE_NAME],
        options,
        host,
    });
}

/** The expression under parentheses, type assertions and `!`. */
export function unwrap(expression: ts.Expression): ts.Expression {
    let node = expression;

    while (
        ts.isParenthesizedExpression(node) ||
        ts.isAsExpression(node) ||
        ts.isSatisfiesExpression(node) ||
        ts.isNonNullExpression(node) ||
        ts.isTypeAssertionExpression(node)
    ) {
        node = node.expression;
    }

    return node;
}

/**
 * The key a literal names (`"a"`, `0`, `-1`, `` `a` ``), resolved by the parser
 * so Unicode escapes are already decoded, or null when it is not a literal.
 */
export function literalKeyOf(node: ts.Expression): string | null {
    if (ts.isStringLiteralLike(node) || ts.isNumericLiteral(node)) {
        return node.text;
    }

    if (
        ts.isPrefixUnaryExpression(node) &&
        node.operator === ts.SyntaxKind.MinusToken &&
        ts.isNumericLiteral(node.operand)
    ) {
        return `-${node.operand.text}`;
    }

    return null;
}

/** The literal key of an object literal entry, or null for anything else. */
export function objectEntryKey(
    property: ts.ObjectLiteralElementLike,
): string | null {
    if (ts.isShorthandPropertyAssignment(property)) {
        return property.name.text;
    }

    if (!ts.isPropertyAssignment(property)) {
        return null;
    }

    const name = property.name;

    return ts.isIdentifier(name) || ts.isStringLiteral(name) ? name.text : null;
}

export function isBooleanLiteral(node: ts.Expression): boolean {
    return (
        node.kind === ts.SyntaxKind.TrueKeyword ||
        node.kind === ts.SyntaxKind.FalseKeyword
    );
}

export function isNullishLiteral(node: ts.Expression): boolean {
    return (
        node.kind === ts.SyntaxKind.NullKeyword ||
        node.kind === ts.SyntaxKind.FalseKeyword ||
        (ts.isIdentifier(node) && node.text === 'undefined')
    );
}

export function isAssignmentOperator(kind: ts.SyntaxKind): boolean {
    return (
        kind >= ts.SyntaxKind.FirstAssignment &&
        kind <= ts.SyntaxKind.LastAssignment
    );
}

export function isBindingPattern(node: ts.Node): node is ts.BindingPattern {
    return ts.isObjectBindingPattern(node) || ts.isArrayBindingPattern(node);
}

export function hasModifier(node: ts.Node, kind: ts.SyntaxKind): boolean {
    return (
        ts.canHaveModifiers(node) &&
        (ts.getModifiers(node) ?? []).some((modifier) => modifier.kind === kind)
    );
}

/**
 * Declarations whose `name` is a binding or a key, not a reference to
 * something else: variables, functions, parameters and object literal members.
 */
export function isNamedDeclaration(node: ts.Node): node is ts.NamedDeclaration {
    return (
        ts.isVariableDeclaration(node) ||
        ts.isFunctionDeclaration(node) ||
        ts.isFunctionExpression(node) ||
        ts.isParameter(node) ||
        ts.isPropertyAssignment(node) ||
        ts.isMethodDeclaration(node) ||
        ts.isGetAccessorDeclaration(node) ||
        ts.isSetAccessorDeclaration(node)
    );
}

/** Types are erased on compile, so nothing in them reaches runtime. */
export function isTypeOnlyNode(node: ts.Node): boolean {
    return (
        ts.isTypeNode(node) ||
        ts.isTypeAliasDeclaration(node) ||
        ts.isInterfaceDeclaration(node) ||
        ts.isTypeParameterDeclaration(node)
    );
}
