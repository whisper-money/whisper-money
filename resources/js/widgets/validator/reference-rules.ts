import ts from 'typescript';
import {
    FORBIDDEN_PROPERTY_NAMES,
    SAFE_GLOBALS,
    SAFE_STATIC_METHODS,
} from './allowlist';
import {
    isAssignmentOperator,
    isBindingPattern,
    literalKeyOf,
    unwrap,
} from './ast-helpers';
import type { ValidationContext } from './validation-context';

const COMPUTED_KEY_HINT =
    'To read a computed key use `get(obj, key)` from the SDK, or `list.at(i)` for an array index; to group values by key, use a `new Map()`.';

/**
 * A name used as a value. It must be declared or imported in the widget, or
 * be one of the safe globals; imported components may only appear as tags.
 */
export function checkReference(
    context: ValidationContext,
    identifier: ts.Identifier,
    symbol = context.checker.getSymbolAtLocation(identifier),
): void {
    const name = identifier.text;

    if (symbol && context.isDeclaredInWidget(symbol)) {
        if (context.imports.get(symbol)?.props) {
            context.report(
                identifier,
                'component-value',
                `\`${name}\` is a component: render it as <${name} />. Using it as a value would skip the prop checks.`,
            );
        }

        return;
    }

    if (SAFE_GLOBALS.has(name)) {
        return;
    }

    const staticMethods = SAFE_STATIC_METHODS.get(name);

    if (!staticMethods) {
        context.report(identifier, 'global', unknownGlobalMessage(name));

        return;
    }

    const parent = identifier.parent;

    if (
        !ts.isPropertyAccessExpression(parent) ||
        parent.expression !== identifier ||
        !staticMethods.has(parent.name.text)
    ) {
        context.report(
            identifier,
            'global',
            `Only ${[...staticMethods].map((method) => `${name}.${method}`).join(', ')} are available from \`${name}\`.`,
        );
    }
}

export function checkPropertyAccess(
    context: ValidationContext,
    node: ts.PropertyAccessExpression,
): void {
    if (ts.isPrivateIdentifier(node.name)) {
        context.report(node.name, 'syntax', 'Private names are not allowed.');
    } else {
        checkPropertyName(context, node.name, node.name.text);
    }

    context.visit(node.expression);
}

export function checkElementAccess(
    context: ValidationContext,
    node: ts.ElementAccessExpression,
): void {
    const key = unwrap(node.argumentExpression);

    checkLiteralKey(
        context,
        key,
        `Brackets only take a literal (\`row["name"]\`, \`list[0]\`). ${COMPUTED_KEY_HINT}`,
    );
    context.visit(node.expression);
}

export function checkShorthandProperty(
    context: ValidationContext,
    node: ts.ShorthandPropertyAssignment,
): void {
    checkPropertyName(context, node.name, node.name.text);
    checkReference(
        context,
        node.name,
        context.checker.getShorthandAssignmentValueSymbol(node),
    );

    if (node.objectAssignmentInitializer) {
        context.visit(node.objectAssignmentInitializer);
    }
}

/** `const { a: b = 1, [k]: c } = obj` reads `a` and `k` from `obj`. */
export function checkBindingElement(
    context: ValidationContext,
    node: ts.BindingElement,
): void {
    const property = node.propertyName ?? node.name;

    if (ts.isObjectBindingPattern(node.parent)) {
        if (ts.isComputedPropertyName(property)) {
            checkLiteralKey(
                context,
                unwrap(property.expression),
                `Destructuring only takes literal keys. ${COMPUTED_KEY_HINT}`,
            );
        } else if (!isBindingPattern(property)) {
            checkPropertyName(context, property, property.text);
        }
    }

    if (isBindingPattern(node.name)) {
        context.visit(node.name);
    }

    if (node.initializer) {
        context.visit(node.initializer);
    }
}

/**
 * Destructuring assignments (`({ [k]: v } = x)`) read computed keys through
 * object literals, and reassigning a function swaps a handler that was
 * checked to have no parameters for one that has them.
 */
export function checkBinaryExpression(
    context: ValidationContext,
    node: ts.BinaryExpression,
): void {
    if (isAssignmentOperator(node.operatorToken.kind)) {
        const left = unwrap(node.left);

        if (
            ts.isObjectLiteralExpression(left) ||
            ts.isArrayLiteralExpression(left)
        ) {
            context.report(
                left,
                'destructuring-assignment',
                'Destructuring assignments are not allowed. Destructure in a declaration instead: `const { a } = obj`.',
            );
        }

        if (ts.isIdentifier(left) && context.localFunctionOf(left)) {
            context.report(
                left,
                'reassign-function',
                `Functions cannot be reassigned. Declare a new function instead of overwriting \`${left.text}\`.`,
            );
        }
    }

    context.visitChildren(node);
}

export function checkLoop(
    context: ValidationContext,
    node: ts.ForOfStatement | ts.ForInStatement,
): void {
    const target = node.initializer;

    if (
        ts.isObjectLiteralExpression(target) ||
        ts.isArrayLiteralExpression(target)
    ) {
        context.report(
            target,
            'destructuring-assignment',
            'Destructure loop items in a declaration: `for (const { a } of rows)`.',
        );
    }

    context.visitChildren(node);
}

/**
 * Walk what a declaration contains without treating its own name as a
 * reference. Literal keys of object literals are still checked: `{ __proto__:
 * x }` sets the prototype. Computed keys only write, so they are walked as
 * normal code.
 */
export function checkDeclaration(
    context: ValidationContext,
    node: ts.NamedDeclaration,
): void {
    const name = node.name;

    if (name && ts.isObjectLiteralElement(node)) {
        const key = ts.isComputedPropertyName(name)
            ? literalKeyOf(unwrap(name.expression))
            : (literalKeyOf(name as ts.Expression) ??
              (ts.isIdentifier(name) ? name.text : null));

        if (key !== null) {
            checkPropertyName(context, name, key);
        }
    }

    ts.forEachChild(node, (child) => {
        if (
            child !== name ||
            isBindingPattern(child) ||
            ts.isComputedPropertyName(child)
        ) {
            context.visit(child);
        }
    });
}

function checkLiteralKey(
    context: ValidationContext,
    key: ts.Expression,
    message: string,
): void {
    const literal = literalKeyOf(key);

    if (literal === null) {
        context.report(key, 'computed-access', message);
    } else {
        checkPropertyName(context, key, literal);
    }
}

function checkPropertyName(
    context: ValidationContext,
    node: ts.Node,
    name: string,
): void {
    if (FORBIDDEN_PROPERTY_NAMES.has(name)) {
        context.report(
            node,
            'forbidden-property',
            `The property "${name}" is not available in widgets: it leads to prototypes or the page. If your data has a field with this name, read it through Object.entries(row).`,
        );
    }
}

function unknownGlobalMessage(name: string): string {
    return `\`${name}\` is not available in widgets. The only globals are ${[...SAFE_GLOBALS].join(', ')}, plus Array.isArray/from and Object.keys/values/entries/fromEntries. Data comes from useWidgetData() and the environment from useWidgetEnv().`;
}
