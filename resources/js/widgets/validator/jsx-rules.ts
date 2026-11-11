import ts from 'typescript';
import {
    COMMON_ATTRIBUTES,
    HTML_ATTRIBUTES,
    HTML_TAGS,
    NATIVE_HANDLERS,
    SVG_ATTRIBUTES,
    SVG_TAGS,
} from './allowlist';
import type { ComponentProps, PropKind } from './component-props';
import { checkPropValue, type JsxAttributeValue } from './prop-values';
import type { ValidationContext } from './validation-context';
import { IMPORTABLE_MODULES } from './widget-modules';

interface ParsedAttribute {
    name: string;
    node: ts.JsxAttribute;
    value: JsxAttributeValue;
}

type JsxOpening = ts.JsxOpeningElement | ts.JsxSelfClosingElement;

const RENDER_PROPS: ReadonlySet<string> = new Set([
    'content',
    'shape',
    'label',
    'dot',
    'activeDot',
    'customized',
]);

/**
 * Check an element: its tag must be an allowed native tag, an imported
 * component or a function declared in the widget, and each attribute must be
 * in that tag's closed list.
 */
export function checkJsxElement(
    context: ValidationContext,
    node: ts.JsxElement | ts.JsxSelfClosingElement,
): void {
    checkOpening(context, ts.isJsxElement(node) ? node.openingElement : node);

    if (ts.isJsxElement(node)) {
        node.children.forEach(context.visit);
    }
}

/** `{…}` children: no spreads, and no functions (render props by another name). */
export function checkJsxChild(
    context: ValidationContext,
    node: ts.JsxExpression,
): void {
    if (node.dotDotDotToken) {
        context.report(
            node,
            'jsx-spread',
            'Spread children (`{...items}`) are not allowed. Map them instead.',
        );
    }

    if (node.expression && context.isFunctionValue(node.expression)) {
        context.report(
            node.expression,
            'render-function',
            'Functions cannot be passed as JSX children. Render the elements directly.',
        );
    }

    context.visitChildren(node);
}

function checkOpening(context: ValidationContext, opening: JsxOpening): void {
    const tag = opening.tagName;

    if (ts.isIdentifier(tag) && /^[a-z]/.test(tag.text)) {
        checkNativeElement(context, tag.text, opening);

        return;
    }

    const binding = context.importedBindingOf(tag);

    if (binding?.props) {
        checkComponentAttributes(
            context,
            (tag as ts.Identifier).text,
            binding.props,
            opening,
        );

        return;
    }

    if (!binding && ts.isIdentifier(tag) && context.localFunctionOf(tag)) {
        // A component declared in the widget gets whatever props it is given:
        // its own body is validated like the rest of the file.
        for (const attribute of parseAttributes(context, opening)) {
            if (attribute.value !== true) {
                context.visit(attribute.value);
            }
        }

        return;
    }

    context.report(
        tag,
        'jsx-tag',
        `<${tag.getText(context.sourceFile)}> must be an allowed native tag, a component imported from ${IMPORTABLE_MODULES}, or a function declared in this file. A variable holding a tag name is not allowed.`,
    );
}

function checkNativeElement(
    context: ValidationContext,
    tag: string,
    opening: JsxOpening,
): void {
    const isSvg = SVG_TAGS.has(tag);

    if (!isSvg && !HTML_TAGS.has(tag)) {
        context.report(
            opening.tagName,
            'jsx-tag',
            `<${tag}> is not allowed. Native tags: ${[...HTML_TAGS].join(', ')}; SVG: ${[...SVG_TAGS].join(', ')}. Bank logos go through <BankLogo> from the SDK.`,
        );

        return;
    }

    for (const { name, node, value } of parseAttributes(context, opening)) {
        const kind = nativeAttributeKind(tag, isSvg, name);

        if (kind === null) {
            context.report(
                node,
                'jsx-attribute',
                `\`${name}\` is not allowed on <${tag}>. Allowed: ${nativeAttributesOf(tag, isSvg).join(', ')} and aria-*.`,
            );
        } else if (name === 'type') {
            checkButtonType(context, tag, node, value);
        } else {
            checkPropValue(context, `${name} on <${tag}>`, kind, value, node);
        }
    }
}

function checkComponentAttributes(
    context: ValidationContext,
    component: string,
    props: ComponentProps,
    opening: JsxOpening,
): void {
    for (const { name, node, value } of parseAttributes(context, opening)) {
        const kind = name.startsWith('aria-')
            ? 'value'
            : Object.hasOwn(props, name)
              ? props[name]
              : null;

        if (kind === null) {
            context.report(
                node,
                'component-prop',
                `<${component}> does not accept \`${name}\` in widgets. Allowed props: ${Object.keys(props).join(', ')}.${unknownPropHint(name)}`,
            );
        } else {
            checkPropValue(
                context,
                `${name} on <${component}>`,
                kind,
                value,
                node,
            );
        }
    }
}

function checkButtonType(
    context: ValidationContext,
    tag: string,
    node: ts.JsxAttribute,
    value: JsxAttributeValue,
): void {
    const isButtonLiteral =
        value !== true && ts.isStringLiteral(value) && value.text === 'button';

    if (tag !== 'button' || !isButtonLiteral) {
        context.report(
            node,
            'jsx-attribute',
            '`type` is only allowed as `type="button"` on <button>.',
        );
    }
}

/** Attributes by name and value; spreads and namespaced names are reported. */
function parseAttributes(
    context: ValidationContext,
    opening: JsxOpening,
): ParsedAttribute[] {
    return opening.attributes.properties.flatMap((attribute) => {
        if (ts.isJsxSpreadAttribute(attribute)) {
            context.report(
                attribute,
                'jsx-spread',
                'Spreading props (`{...props}`) is not allowed on any JSX element. Pass each prop by name.',
            );

            return [];
        }

        if (!ts.isIdentifier(attribute.name)) {
            context.report(
                attribute,
                'jsx-attribute',
                'Namespaced attributes (`xlink:href`) are not allowed.',
            );

            return [];
        }

        return [
            {
                name: attribute.name.text,
                node: attribute,
                value: attributeValue(attribute),
            },
        ];
    });
}

function attributeValue(attribute: ts.JsxAttribute): JsxAttributeValue {
    const initializer = attribute.initializer;

    if (!initializer) {
        return true;
    }

    return ts.isJsxExpression(initializer)
        ? (initializer.expression ?? true)
        : initializer;
}

function nativeAttributeKind(
    tag: string,
    isSvg: boolean,
    name: string,
): PropKind | null {
    switch (name) {
        case 'className':
            return 'className';
        case 'style':
            return 'style';
        case 'fill':
        case 'stroke':
            return isSvg ? 'color' : null;
        case 'id':
            return tag === 'linearGradient' ? 'value' : null;
    }

    if (NATIVE_HANDLERS.has(name)) {
        return 'handler';
    }

    const allowed =
        COMMON_ATTRIBUTES.has(name) ||
        name.startsWith('aria-') ||
        (isSvg ? SVG_ATTRIBUTES : HTML_ATTRIBUTES).has(name);

    return allowed ? 'value' : null;
}

function nativeAttributesOf(tag: string, isSvg: boolean): string[] {
    return [
        ...COMMON_ATTRIBUTES,
        ...(isSvg ? SVG_ATTRIBUTES : HTML_ATTRIBUTES),
        ...NATIVE_HANDLERS,
    ].filter((attribute) => attribute !== 'id' || tag === 'linearGradient');
}

function unknownPropHint(name: string): string {
    if (/^on[A-Z]/.test(name)) {
        return ' Event handlers on charts and icons receive the native event, so they are not available.';
    }

    if (RENDER_PROPS.has(name)) {
        return ' Render props are not available; for tooltips use <ChartTooltip> from the SDK.';
    }

    return '';
}
