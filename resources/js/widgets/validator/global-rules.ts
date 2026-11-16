import ts from 'typescript';
import {
    CALLBACK_METHODS,
    CONSTRUCTOR_GLOBALS,
    NAMESPACE_GLOBALS,
} from './allowlist';
import { unwrap } from './ast-helpers';
import type { ValidationContext } from './validation-context';

/**
 * A widget runs in the app's own JavaScript realm: `Math`, `JSON`, `Array`,
 * `Date`… are the app's too, and so are the modules it imports. Changing any
 * of them (`Array.from = …`, `Math = …`, `wm.download = …`) would make the
 * app, React or a library call the widget's code with their own objects, DOM
 * nodes included. So a widget may never write to a global or an import, nor
 * to anything reached from one, and the shared globals may only be used in
 * the positions that cannot hand them over to code that writes to them.
 */

const ROOT_HINT =
    'Keep a copy of your own instead, in a variable declared in the widget (`const total = { ...x }`, `let count = 0`).';

/**
 * Where a write lands: the name at the root of `a.b[0].c()`, `(a as X).b`,
 * `(0, a).b`. Null when the root is not a name (a literal, a call result of
 * an inline function…), which can only be the widget's own value.
 */
function writeRoot(target: ts.Expression): ts.Identifier | null {
    let node = unwrap(target);

    for (;;) {
        if (
            ts.isPropertyAccessExpression(node) ||
            ts.isElementAccessExpression(node) ||
            ts.isCallExpression(node)
        ) {
            node = unwrap(node.expression);
        } else if (ts.isTaggedTemplateExpression(node)) {
            node = unwrap(node.tag);
        } else if (
            ts.isBinaryExpression(node) &&
            node.operatorToken.kind === ts.SyntaxKind.CommaToken
        ) {
            node = unwrap(node.right);
        } else {
            break;
        }
    }

    return ts.isIdentifier(node) ? node : null;
}

/**
 * Report a write (`=`, `+=`, `??=`, `++`, `--`, `delete`, a `for…of` target)
 * whose target is, or is reached from, a global or an import.
 */
export function checkWriteTarget(
    context: ValidationContext,
    target: ts.Expression,
): void {
    const root = writeRoot(target);

    if (root === null) {
        return;
    }

    const symbol = context.checker.getSymbolAtLocation(root);
    const isGlobal = !symbol || !context.isDeclaredInWidget(symbol);
    const isImport = symbol !== undefined && context.imports.has(symbol);

    if (!isGlobal && !isImport) {
        return;
    }

    const what = isGlobal ? 'a global shared with the app' : 'an import';

    context.report(
        target,
        'mutate-global',
        `Widgets cannot change \`${root.text}\` or anything reached from it: it is ${what}. ${ROOT_HINT}`,
    );
}

/** `++x`, `x--`. */
export function checkUpdateExpression(
    context: ValidationContext,
    node: ts.PrefixUnaryExpression | ts.PostfixUnaryExpression,
): void {
    if (
        node.operator === ts.SyntaxKind.PlusPlusToken ||
        node.operator === ts.SyntaxKind.MinusMinusToken
    ) {
        checkWriteTarget(context, node.operand);
    }

    context.visitChildren(node);
}

/** `delete x.y`. */
export function checkDelete(
    context: ValidationContext,
    node: ts.DeleteExpression,
): void {
    checkWriteTarget(context, node.expression);
    context.visitChildren(node);
}

/** The node itself, or the parentheses, `as`, `satisfies` and `!` around it. */
function outermost(node: ts.Node): ts.Node {
    let current = node;

    while (
        (ts.isParenthesizedExpression(current.parent) ||
            ts.isAsExpression(current.parent) ||
            ts.isSatisfiesExpression(current.parent) ||
            ts.isNonNullExpression(current.parent) ||
            ts.isTypeAssertionExpression(current.parent)) &&
        current.parent.expression === current
    ) {
        current = current.parent;
    }

    return current;
}

/** `node.x` or `node["x"]`: a member of it is read (or written, see checkWriteTarget). */
function isMemberObject(node: ts.Node): boolean {
    const parent = node.parent;

    return (
        (ts.isPropertyAccessExpression(parent) ||
            ts.isElementAccessExpression(parent)) &&
        parent.expression === node
    );
}

function isCallee(node: ts.Node): boolean {
    const parent = node.parent;

    return (
        (ts.isCallExpression(parent) || ts.isNewExpression(parent)) &&
        parent.expression === node
    );
}

/** `x instanceof Date`. */
function isInstanceofTarget(node: ts.Node): boolean {
    const parent = node.parent;

    return (
        ts.isBinaryExpression(parent) &&
        parent.operatorToken.kind === ts.SyntaxKind.InstanceOfKeyword &&
        parent.right === node
    );
}

/** `rows.map(Number)`, `xs.filter(Boolean)`, `Array.from(xs, Number)`. */
function isCallbackArgument(node: ts.Node): boolean {
    const parent = node.parent;

    if (
        !ts.isCallExpression(parent) ||
        !parent.arguments.includes(node as ts.Expression)
    ) {
        return false;
    }

    const callee = unwrap(parent.expression);

    return (
        ts.isPropertyAccessExpression(callee) &&
        CALLBACK_METHODS.has(callee.name.text)
    );
}

/**
 * A shared global used as a value. Math, JSON and Intl may only have a member
 * read (for Intl, a member that is then called, constructed or read from:
 * `Intl.NumberFormat` is itself a constructor with statics). Number, String,
 * Boolean, Date, Map and Set may also be called, constructed, used after
 * `instanceof` and handed to an array callback. Other safe globals
 * (`parseInt`, `NaN`…) are plain values.
 */
export function checkSharedGlobalUse(
    context: ValidationContext,
    identifier: ts.Identifier,
): void {
    const name = identifier.text;
    const node = outermost(identifier);

    if (NAMESPACE_GLOBALS.has(name)) {
        if (!isMemberObject(node)) {
            context.report(
                identifier,
                'global',
                `\`${name}\` can only be used through its members, as in \`${name === 'Intl' ? 'new Intl.NumberFormat(locale)' : `${name}.${name === 'JSON' ? 'stringify(x)' : 'max(a, b)'}`}\`: it cannot be kept in a variable, an object, an array or passed as an argument.`,
            );
        } else if (name === 'Intl') {
            const member = outermost(node.parent);

            if (!isCallee(member) && !isMemberObject(member)) {
                context.report(
                    node.parent,
                    'global',
                    'Use Intl constructors directly, as in `new Intl.NumberFormat(locale, options).format(x)`: they cannot be kept in a variable or passed around.',
                );
            }
        }

        return;
    }

    if (
        CONSTRUCTOR_GLOBALS.has(name) &&
        !isMemberObject(node) &&
        !isCallee(node) &&
        !isInstanceofTarget(node) &&
        !isCallbackArgument(node)
    ) {
        context.report(
            identifier,
            'global',
            `\`${name}\` can only be called (\`${name}(x)\`${name === 'Map' || name === 'Set' || name === 'Date' ? `, \`new ${name}()\`` : ''}), used through its members, after instanceof, or handed straight to an array method (\`rows.map(${name})\`): it cannot be kept in a variable, an object or an array.`,
        );
    }
}
