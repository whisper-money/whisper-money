import { fireEvent, render } from '@testing-library/react';
import * as React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ChartTooltipPortal } from './chart';

/**
 * Renders the portal inside a `.recharts-wrapper` (the ancestor its layout
 * effect looks up) and exposes a button that bumps unrelated state to force a
 * re-render without touching `coordinate`.
 */
function Harness({ coordinate }: { coordinate: { x: number; y: number } }) {
    const [, setTick] = React.useState(0);

    return (
        <div className="recharts-wrapper">
            <button onClick={() => setTick((t) => t + 1)}>rerender</button>
            <ChartTooltipPortal coordinate={coordinate}>
                <span>tooltip</span>
            </ChartTooltipPortal>
        </div>
    );
}

describe('ChartTooltipPortal', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    // The positioning effect reads the wrapper's rect, so a call to
    // getBoundingClientRect is a proxy for "the effect ran".
    it('does not recompute its position on a re-render with an unchanged coordinate (regression: PHP-LARAVEL-3B render loop)', () => {
        const rectSpy = vi.spyOn(Element.prototype, 'getBoundingClientRect');
        const { getByText } = render(<Harness coordinate={{ x: 10, y: 20 }} />);

        const afterMount = rectSpy.mock.calls.length;
        expect(afterMount).toBeGreaterThan(0);

        fireEvent.click(getByText('rerender'));
        fireEvent.click(getByText('rerender'));

        // With the pre-fix dependency-less effect this count would climb on
        // every render and the setPos feedback loop would eventually throw
        // "Maximum update depth exceeded".
        expect(rectSpy.mock.calls.length).toBe(afterMount);
    });

    it('recomputes its position when the coordinate changes', () => {
        const rectSpy = vi.spyOn(Element.prototype, 'getBoundingClientRect');
        const { rerender } = render(<Harness coordinate={{ x: 10, y: 20 }} />);

        const afterMount = rectSpy.mock.calls.length;

        rerender(<Harness coordinate={{ x: 200, y: 300 }} />);

        expect(rectSpy.mock.calls.length).toBeGreaterThan(afterMount);
    });
});
