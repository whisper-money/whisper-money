import { describe, expect, it } from 'vitest';
import { InlineLinkError, linkWidgetModuleInline } from './link-inline';

const registry = {
    react: { useMemo: () => 'memo' },
    'react/jsx-runtime': { jsx: () => 'element' },
    recharts: {},
    'lucide-react': {},
    '@whisper-money/widget-sdk': { useWidgetData: () => ({}) },
};

/** How Bun ends a compiled widget, with the import prelude it starts with. */
const compiled =
    'import{useMemo as u}from"react";import{jsx as e}from"react/jsx-runtime";function B(){return e("div",{children:u()})}export{B as default};';

describe('linkWidgetModuleInline', () => {
    it('binds every import to the registry and reports the default export', () => {
        const linked = linkWidgetModuleInline(compiled, registry, 'report');

        expect(linked).not.toMatch(/\bimport\b|\bexport\b/);

        const reported: unknown[] = [];
        const global = globalThis as unknown as Record<symbol, unknown>;
        global[Symbol.for('whisper-money.widget-modules')] = registry;
        global[Symbol.for('report')] = (component: unknown) =>
            reported.push(component);

        new Function(linked)();

        expect(reported).toHaveLength(1);
        expect((reported[0] as () => unknown)()).toBe('element');
    });

    it('refuses what the loader refuses', () => {
        expect(() =>
            linkWidgetModuleInline(
                'import{x as a}from"https://evil.test/x.js";function B(){}export{B as default};',
                registry,
                'report',
            ),
        ).toThrow(InlineLinkError);
        expect(() =>
            linkWidgetModuleInline(
                'import{createElement as c}from"react";function B(){}export{B as default};',
                registry,
                'report',
            ),
        ).toThrow('"createElement" is not available from "react".');
        expect(() =>
            linkWidgetModuleInline('function B(){}', registry, 'report'),
        ).toThrow('no default export');
    });
});
