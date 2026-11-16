// @vitest-environment node
import { forwardRef, memo } from 'react';
import { describe, expect, it } from 'vitest';
import { deepFreeze, freezeExport, freezeModule } from './freeze';

describe('deepFreeze', () => {
    it('freezes data all the way down', () => {
        const data = deepFreeze({
            tx: { data: [{ id: 't1', amount: -100, labels: [{ id: 'l1' }] }] },
        });

        expect(Object.isFrozen(data)).toBe(true);
        expect(Object.isFrozen(data.tx.data)).toBe(true);
        expect(Object.isFrozen(data.tx.data[0])).toBe(true);
        expect(Object.isFrozen(data.tx.data[0].labels[0])).toBe(true);
        expect(() => {
            (data.tx.data[0] as { amount: number }).amount = 1;
        }).toThrow(TypeError);
    });

    it('leaves primitives and null alone, and survives cycles', () => {
        expect(deepFreeze(null)).toBeNull();
        expect(deepFreeze(3)).toBe(3);

        const cycle: Record<string, unknown> = {};
        cycle.self = cycle;

        expect(Object.isFrozen(deepFreeze(cycle))).toBe(true);
    });
});

describe('freezeModule', () => {
    it('freezes the module, its exports and what forwardRef and memo wrap', () => {
        const render = () => null;
        const Plain = () => null;
        const Wrapped = memo(Plain);
        const Forwarded = forwardRef(render);
        const module = freezeModule({ Plain, Wrapped, Forwarded, n: 1 });

        expect(Object.isFrozen(module)).toBe(true);
        expect(Object.isFrozen(Plain)).toBe(true);
        expect(Object.isFrozen(Wrapped)).toBe(true);
        expect(Object.isFrozen(Forwarded)).toBe(true);
        expect(Object.isFrozen(render)).toBe(true);
        expect(freezeExport('text')).toBe('text');
    });
});
