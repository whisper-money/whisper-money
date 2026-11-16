import ts from 'typescript';
import { hasExternalUrl, SDK_MODULE, STYLE_PROPERTIES } from './allowlist';
import {
    isBooleanLiteral,
    isNullishLiteral,
    objectEntryKey,
    unwrap,
} from './ast-helpers';
import { describeUnknownClass } from './class-names';
import type { PropKind } from './component-props';
import type { ValidationContext } from './validation-context';

/** A JSX attribute's value; `true` for a bare attribute (`<Line dot />`). */
export type JsxAttributeValue = ts.Expression | true;

const STYLE_KEYS: Readonly<Record<string, PropKind>> = Object.fromEntries(
    [...STYLE_PROPERTIES].map((key) => [key, 'value']),
);

const TICK_KEYS: Readonly<Record<string, PropKind>> = {
    fontSize: 'value',
    fontWeight: 'value',
    opacity: 'value',
    angle: 'value',
    dx: 'value',
    dy: 'value',
    textAnchor: 'value',
    fill: 'color',
};

/**
 * Check one prop value against its kind (see PropKind) and walk whatever code
 * it contains.
 */
export function checkPropValue(
    context: ValidationContext,
    label: string,
    kind: PropKind,
    value: JsxAttributeValue,
    node: ts.Node,
): void {
    if (value === true) {
        if (kind === 'className' || kind === 'style' || kind === 'handler') {
            context.report(
                node,
                'jsx-attribute',
                `\`${label}\` needs a value.`,
            );
        }

        return;
    }

    switch (kind) {
        case 'className':
            checkClassName(context, value);

            return;
        case 'style':
            checkKeyedObject(context, label, value, STYLE_KEYS, 'style');

            return;
        case 'tick':
            if (!isBooleanLiteral(unwrap(value))) {
                checkKeyedObject(
                    context,
                    label,
                    value,
                    TICK_KEYS,
                    'component-prop',
                );
            }

            return;
        case 'handler':
            checkHandler(context, label, value);
            break;
        case 'color':
            checkNoExternalUrl(context, value);
            break;
        case 'boolean':
            checkBoolean(context, label, value);
            break;
        case 'text':
            checkText(context, label, value);
            break;
        case 'value':
            checkPlainValue(context, label, value);
            break;
        case 'formatter':
            break;
    }

    context.visit(value);
}

/**
 * `url()` only ever appears as a literal `url(#id)` pointing at a gradient
 * declared in the widget; any other string literal with `url(` is reported.
 */
export function checkNoExternalUrl(
    context: ValidationContext,
    node: ts.Node,
): void {
    if (
        (ts.isStringLiteralLike(node) || ts.isTemplateLiteralToken(node)) &&
        hasExternalUrl(node.text)
    ) {
        context.report(
            node,
            'url',
            '`url()` is only allowed as a literal reference to a gradient in the widget, e.g. fill="url(#barGradient)".',
        );
    }

    ts.forEachChild(node, (child) => checkNoExternalUrl(context, child));
}

/**
 * An object literal whose keys come from a closed list (`style`, Recharts
 * style props, `tick`), each value checked by its kind. A variable, a spread
 * or a computed key would hide which keys reach the component.
 */
function checkKeyedObject(
    context: ValidationContext,
    label: string,
    value: ts.Expression,
    keys: Readonly<Record<string, PropKind>>,
    code: string,
): void {
    const object = unwrap(value);
    const allowed = Object.keys(keys).join(', ');

    if (!ts.isObjectLiteralExpression(object)) {
        context.report(
            value,
            code,
            `\`${label}\` must be an object literal with keys from: ${allowed}.`,
        );

        return;
    }

    for (const property of object.properties) {
        const key = objectEntryKey(property);

        if (key === null || !Object.hasOwn(keys, key)) {
            context.report(
                property,
                code,
                `\`${key ?? 'this entry'}\` is not allowed in \`${label}\`. Allowed keys: ${allowed}.`,
            );

            continue;
        }

        if (ts.isPropertyAssignment(property)) {
            checkPropValue(
                context,
                `${key} in ${label}`,
                keys[key],
                property.initializer,
                property,
            );
        } else {
            context.visit(property);
        }
    }
}

function checkClassName(
    context: ValidationContext,
    value: ts.Expression,
): void {
    for (const literal of classLiterals(context, value)) {
        for (const className of literal.text.split(/\s+/).filter(Boolean)) {
            const problem = describeUnknownClass(
                className,
                context.options.classNames,
            );

            if (problem) {
                context.report(literal, 'class', problem);
            }
        }
    }
}

/**
 * The string literals a `className` expression can produce. Conditions are
 * walked as normal code; anything that is not a literal, a condition between
 * literals or `cn()` of those is reported, because the classes it would
 * produce cannot be checked.
 */
function classLiterals(
    context: ValidationContext,
    expression: ts.Expression,
): ts.StringLiteralLike[] {
    const node = unwrap(expression);

    if (ts.isStringLiteralLike(node)) {
        return [node];
    }

    if (isNullishLiteral(node)) {
        return [];
    }

    if (ts.isConditionalExpression(node)) {
        context.visit(node.condition);

        return [
            ...classLiterals(context, node.whenTrue),
            ...classLiterals(context, node.whenFalse),
        ];
    }

    if (
        ts.isBinaryExpression(node) &&
        node.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
    ) {
        context.visit(node.left);

        return classLiterals(context, node.right);
    }

    if (
        ts.isCallExpression(node) &&
        context.isImported(node.expression, SDK_MODULE, 'cn')
    ) {
        return node.arguments.flatMap((argument) =>
            classLiterals(context, argument),
        );
    }

    context.report(
        node,
        'class',
        "className must be literal classes so they can be checked: a string, a condition between strings (`ok ? 'text-success' : 'text-destructive'`), or `cn()` from the SDK with those. Variables and lookups like `TONES[kind]` are not allowed.",
    );

    return [];
}

function checkHandler(
    context: ValidationContext,
    label: string,
    value: ts.Expression,
): void {
    const fn = context.resolveFunction(value);

    if (!fn || fn.parameters.length > 0) {
        context.report(
            value,
            'handler',
            `\`${label}\` must be a function without parameters declared in this file, e.g. onClick={() => setOpen(true)}. Handlers never receive the event.`,
        );
    }
}

function checkBoolean(
    context: ValidationContext,
    label: string,
    value: ts.Expression,
): void {
    if (!isBooleanLiteral(unwrap(value))) {
        context.report(
            value,
            'component-prop',
            `\`${label}\` only takes true or false here. Custom elements and functions are not allowed.`,
        );
    }
}

function checkText(
    context: ValidationContext,
    label: string,
    value: ts.Expression,
): void {
    const node = unwrap(value);

    if (ts.isConditionalExpression(node)) {
        checkText(context, label, node.whenTrue);
        checkText(context, label, node.whenFalse);

        return;
    }

    if (
        !ts.isStringLiteralLike(node) &&
        !ts.isNumericLiteral(node) &&
        !ts.isTemplateExpression(node)
    ) {
        context.report(
            value,
            'component-prop',
            `\`${label}\` only takes text (a string, number or template literal).`,
        );
    }
}

/** Data props never take functions or elements, not even nested. */
function checkPlainValue(
    context: ValidationContext,
    label: string,
    value: ts.Expression,
): void {
    const node = unwrap(value);

    if (
        context.isFunctionValue(node) ||
        ts.isJsxElement(node) ||
        ts.isJsxSelfClosingElement(node) ||
        ts.isJsxFragment(node)
    ) {
        context.report(
            value,
            'component-prop',
            `\`${label}\` cannot be a function or an element: those become render props or callbacks that see the page.`,
        );

        return;
    }

    for (const part of plainValueParts(context, label, node)) {
        checkPlainValue(context, label, part);
    }
}

/** The sub-expressions of a conditional, array or object literal. */
function plainValueParts(
    context: ValidationContext,
    label: string,
    node: ts.Expression,
): ts.Expression[] {
    if (ts.isConditionalExpression(node)) {
        return [node.whenTrue, node.whenFalse];
    }

    if (ts.isArrayLiteralExpression(node)) {
        return [...node.elements];
    }

    if (!ts.isObjectLiteralExpression(node)) {
        return [];
    }

    return node.properties.flatMap((property) => {
        if (ts.isMethodDeclaration(property) || ts.isAccessor(property)) {
            context.report(
                property,
                'component-prop',
                `\`${label}\` cannot contain methods.`,
            );
        }

        return ts.isPropertyAssignment(property) ? [property.initializer] : [];
    });
}
