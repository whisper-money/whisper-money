import { describe, expect, it } from 'vitest';

import { computeTooltipPosition } from './chart-tooltip-position';

const viewport = { viewportW: 1000, viewportH: 800 };

describe('computeTooltipPosition', () => {
    it('places the tooltip below-right of the cursor when it fits', () => {
        const pos = computeTooltipPosition({
            cx: 100,
            cy: 100,
            tipW: 120,
            tipH: 60,
            offset: 12,
            ...viewport,
        });

        expect(pos).toEqual({ x: 112, y: 112 });
    });

    it('flips to the left when it would overflow the right edge', () => {
        const pos = computeTooltipPosition({
            cx: 950,
            cy: 100,
            tipW: 120,
            tipH: 60,
            offset: 12,
            ...viewport,
        });

        // 950 + 12 + 120 = 1082 > 1000 - 8, so it flips: 950 - 120 - 12
        expect(pos.x).toBe(818);
    });

    it('flips upward when it would overflow the bottom edge', () => {
        const pos = computeTooltipPosition({
            cx: 100,
            cy: 780,
            tipW: 120,
            tipH: 60,
            offset: 12,
            ...viewport,
        });

        // 780 + 12 + 60 = 852 > 800 - 8, so it flips: 780 - 60 - 12
        expect(pos.y).toBe(708);
    });

    it('clamps to the 8px margin when even the flipped position is off-screen', () => {
        // Tooltip larger than the space on both sides of the cursor: the flip
        // pushes the coordinate negative, so it must clamp to 8 rather than
        // render partially off-screen.
        const pos = computeTooltipPosition({
            cx: 950,
            cy: 780,
            tipW: 980,
            tipH: 790,
            offset: 12,
            ...viewport,
        });

        expect(pos).toEqual({ x: 8, y: 8 });
    });

    it('rounds to whole pixels so the result is a stable fixed point', () => {
        const input = {
            cx: 100.4,
            cy: 100.6,
            tipW: 120,
            tipH: 60,
            offset: 12,
            ...viewport,
        };
        const first = computeTooltipPosition(input);

        expect(Number.isInteger(first.x)).toBe(true);
        expect(Number.isInteger(first.y)).toBe(true);
        // Same inputs must yield an identical result — the effect relies on this
        // to converge instead of looping.
        expect(computeTooltipPosition(input)).toEqual(first);
    });
});
