// @vitest-environment node
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { FORBIDDEN_PROPERTY_NAMES } from './allowlist';
import { GET_FORBIDDEN_KEYS } from './sdk-spec';
import { validateWidget } from './validate-widget';

const imports = `import { useEffect, useMemo, useState } from 'react';
import { Bar } from 'recharts';
import { Button, get, useWidgetData, wm } from '@whisper-money/widget-sdk';
`;

/** A widget whose body runs `setup` before rendering `jsx`. */
function widget(setup: string, jsx = '<div />'): string {
    return `${imports}
export default function Widget() {
    const data = useWidgetData();
    ${setup}
    return (${jsx});
}`;
}

/** A widget that runs `code` at the top level of its module. */
function topLevel(code: string): string {
    return `${imports}
${code}
export default function Widget() { return <div />; }`;
}

/**
 * The chain the hardening closes, kept as validator input only: a widget that
 * swaps `Array.from` at load, waits for the app to call it with a NodeList and
 * writes HTML through the node. It is never compiled or run.
 */
const CHAIN_FIXTURE = readFileSync(
    path.resolve(
        __dirname,
        '../../../../tests/Fixtures/widgets/rejected/array-from-chain.tsx',
    ),
    'utf8',
);

const WRITES: [name: string, source: string][] = [
    ['static method of Array', topLevel('Array.from = (x) => [];')],
    ['static method of Object', topLevel('Object.keys = (o) => [];')],
    ['JSON.parse', topLevel('JSON.parse = (s) => s;')],
    ['Math.max', topLevel('Math.max = () => 0;')],
    ['Date.now', topLevel('Date.now = () => 0;')],
    ['Intl constructor', topLevel('Intl.NumberFormat = (a) => a;')],
    [
        'static of an Intl constructor',
        topLevel('Intl.NumberFormat.supportedLocalesOf = () => [];'),
    ],
    ['the global itself', topLevel('Math = { max: () => 0 };')],
    ['an unknown global', topLevel('parseInt = () => 0;')],
    ['compound assignment', topLevel('Math.PI += 1;')],
    ['logical assignment', topLevel('Date.now ??= () => 0;')],
    ['prefix increment', topLevel('++Math.PI;')],
    ['postfix decrement', topLevel('Math.PI--;')],
    ['delete', topLevel('delete JSON.parse;')],
    ['delete with optional chaining', topLevel('delete JSON?.parse;')],
    ['bracket literal', topLevel('JSON["parse"] = (s) => s;')],
    ['parenthesised', topLevel('(Math).max = () => 0;')],
    ['as wrapper', topLevel('(Math as any).max = () => 0;')],
    ['non-null wrapper', topLevel('Math!.max = () => 0;')],
    ['comma expression', topLevel('(0, Math).max = () => 0;')],
    ['deep path through a call', topLevel('Array.from([]).length = 3;')],
    [
        'inside a nested function',
        topLevel('function f() { Number.isFinite = () => true; }'),
    ],
    [
        'inside an effect',
        widget('useEffect(() => { Object.entries = () => []; }, []);'),
    ],
    [
        'inside an event handler',
        widget(
            '',
            '<button type="button" onClick={() => { Date.now = () => 0; }}>x</button>',
        ),
    ],
    ['an SDK import', topLevel('wm.download = () => undefined;')],
    ['a component import', topLevel('Button.render = () => null;')],
    ['a React import', topLevel('useMemo.x = 1;')],
    ['a recharts import', topLevel('Bar.defaultProps = {};')],
    ['for-of target', topLevel('for (Math.x of [1]) {}')],
    ['for-in target', topLevel('for (JSON.y in {}) {}')],
];

const ALIASES: [name: string, source: string][] = [
    ['alias variable', topLevel('const M = Math; M.max = () => 0;')],
    ['alias of a constructor', topLevel('const D = Date; D.now = () => 0;')],
    ['alias of an Intl constructor', topLevel('const F = Intl.NumberFormat;')],
    [
        'object literal holding the global',
        topLevel('const o = { m: Math }; o.m.max = () => 0;'),
    ],
    ['shorthand property', topLevel('const o = { JSON };')],
    ['shorthand property of a page global', topLevel('const o = { window };')],
    ['array literal', topLevel('const list = [Date];')],
    ['spread', topLevel('const o = { ...Math };')],
    [
        'argument to an own function',
        topLevel('function f(m) { m.max = () => 0; }\nf(Math);'),
    ],
    [
        'constructor as an argument to an own function',
        topLevel('function f(n) { n.isFinite = () => true; }\nf(Number);'),
    ],
    [
        'default parameter',
        topLevel('function f(m = Math) { m.max = () => 0; }'),
    ],
    [
        'destructuring default',
        topLevel('const { m = JSON } = {}; m.parse = (s) => s;'),
    ],
    ['destructuring the global', topLevel('const { max } = Math;')],
    ['returned', topLevel('function f() { return Set; }')],
    ['ternary', topLevel('const x = true ? Map : null;')],
    ['comma aliasing', topLevel('const x = (0, Math);')],
    ['JSX attribute', widget('', '<div title={String} />')],
];

describe('validateWidget: writes to globals and imports', () => {
    it.each(WRITES)('rejects a write to %s', (_name, source) => {
        expect(validateWidget(source).map((error) => error.code)).toContain(
            'mutate-global',
        );
    });

    it.each(ALIASES)(
        'rejects the shared global through %s',
        (_name, source) => {
            const codes = validateWidget(source).map((error) => error.code);

            expect(codes).toContain('global');
        },
    );

    it('rejects the exact chain that reached script execution', () => {
        const codes = validateWidget(CHAIN_FIXTURE).map((error) => error.code);

        expect(codes).toContain('mutate-global');
        expect(codes).toContain('forbidden-property');
    });

    it('tells the agent to keep a copy of its own', () => {
        const [error] = validateWidget(topLevel('Array.from = (x) => [];'));

        expect(error.code).toBe('mutate-global');
        expect(error.message).toContain('Array');
        expect(error.message).toContain('in a variable declared in the widget');
    });
});

describe('validateWidget: shared methods', () => {
    it.each([
        [
            'hasOwnProperty',
            topLevel(
                'const hop = ({}).hasOwnProperty; hop.call = () => false;',
            ),
        ],
        [
            'slice',
            topLevel('const s = [].slice; s.call = (x) => Array.from(x);'),
        ],
        [
            'toString',
            topLevel(
                "const o = {}; o.toString.call = (v) => '[object Object]';",
            ),
        ],
        ['push', topLevel('[].push.apply = (t, a) => 0;')],
        ['at', topLevel("''.at.call = (s, i) => s;")],
        [
            'destructured call',
            topLevel('const f = () => 1; const { call } = f;'),
        ],
        ['bracketed call', topLevel("const f = () => 1; const c = f['call'];")],
        ['optional call', topLevel('const f = () => 1; const c = f?.call;')],
        ['bind', topLevel('const f = () => 1; const g = f.bind(null);')],
        [
            'call through get()',
            widget("const f = () => 1; const c = get(f, 'call');"),
        ],
    ])('rejects reaching .call/.apply/.bind: %s', (_name, source) => {
        expect(validateWidget(source).map((error) => error.code)).toContain(
            'forbidden-property',
        );
    });
});

describe('validateWidget: DOM and React internals', () => {
    it.each([
        'insertAdjacentHTML',
        'innerHTML',
        'parentElement',
        'srcElement',
        'item',
        'querySelector',
        'stateNode',
        'return',
        'cookie',
        'addEventListener',
    ])('rejects reading .%s', (name) => {
        expect(validateWidget(widget(`const v = data.x.${name};`))).toEqual([
            expect.objectContaining({ code: 'forbidden-property' }),
        ]);
    });

    it('rejects React keys on DOM nodes, dotted, bracketed and through get()', () => {
        for (const setup of [
            'const v = data.__reactProps$abc;',
            "const v = data['__reactFiber$abc'];",
            "const v = get(data, '__reactFiber$abc');",
            'const v = data._reactRootContainer;',
        ]) {
            expect(
                validateWidget(widget(setup)).map((error) => error.code),
            ).toEqual(['forbidden-property']);
        }
    });

    it('keeps return statements and the names data uses', () => {
        expect(
            validateWidget(
                widget(
                    'const top = data.top; const parent = data.parent; const v = data.value; const c = data.children;',
                    '<div style={{ top: 1 }}>{String(top)}{String(parent)}{String(v)}{String(c)}</div>',
                ),
            ),
        ).toEqual([]);
    });

    it('keeps prototypes out of reach of dots and get()', () => {
        for (const name of ['prototype', 'constructor', '__proto__']) {
            expect(FORBIDDEN_PROPERTY_NAMES.has(name)).toBe(true);
            expect(GET_FORBIDDEN_KEYS.has(name)).toBe(true);
        }
    });
});

describe('validateWidget: legitimate idioms stay valid', () => {
    it.each([
        ['map(Number)', widget('const n = [data.a].map(Number);')],
        ['filter(Boolean)', widget('const n = [data.a].filter(Boolean);')],
        [
            'Array.from with Number',
            widget('const n = Array.from([1], Number);'),
        ],
        ['new Map()', widget('const m = new Map(); m.set("a", 1);')],
        ['new Set()', widget('const s = new Set([1]);')],
        [
            'Intl.NumberFormat',
            widget("const s = new Intl.NumberFormat('es-ES').format(1);"),
        ],
        [
            'Intl static method',
            widget("const s = Intl.NumberFormat.supportedLocalesOf(['es']);"),
        ],
        ['Math.max(...xs)', widget('const m = Math.max(...[1, 2]);')],
        ['Math.PI as a value', widget('const r = Math.PI * 2;')],
        ['JSON.stringify', widget('const s = JSON.stringify(data);')],
        ['Date.now()', widget('const t = Date.now();')],
        ['new Date()', widget('const d = new Date(2026, 0, 1);')],
        ['Number(x)', widget('const n = Number(data.a);')],
        ['instanceof Date', widget('const ok = data.a instanceof Date;')],
        ['String.raw', widget('const s = String.raw`a`;')],
        [
            'compound assignment on a local',
            widget(
                'const acc = { total: 0 }; for (const n of [1, 2]) { acc.total += n; }',
            ),
        ],
        [
            'counters and locals',
            widget('let i = 0; i++; --i; const box = { a: 1 }; delete box.a;'),
        ],
        [
            'reduce accumulator',
            widget(
                'const t = [1].reduce((acc, n) => { acc.total += n; return acc; }, { total: 0 });',
            ),
        ],
        ['spread copy', widget('const copy = [...[1, 2]];')],
        ['shadowed global', widget('const Math = { max: 1 }; Math.max = 2;')],
        ['parseFloat as callback', widget('const n = ["1"].map(parseFloat);')],
        ['state setter', widget('const [n, setN] = useState(0); setN(n + 1);')],
    ])('accepts %s', (_name, source) => {
        expect(validateWidget(source)).toEqual([]);
    });
});
