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

    it('rounds to whole pixels so sub-pixel jitter collapses to one coordinate', () => {
        const base = { tipW: 120, tipH: 60, offset: 12, ...viewport };
        const a = computeTooltipPosition({ cx: 100.1, cy: 100.1, ...base });
        const b = computeTooltipPosition({ cx: 100.4, cy: 100.4, ...base });

        expect(Number.isInteger(a.x)).toBe(true);
        expect(Number.isInteger(a.y)).toBe(true);
        // 100.1 and 100.4 both land on the same pixel, so the equality guard
        // sees an unchanged position instead of thrashing on the difference.
        expect(a).toEqual(b);
        expect(a).toEqual({ x: 112, y: 112 });
    });
});
