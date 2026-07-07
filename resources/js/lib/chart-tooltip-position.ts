export interface TooltipPositionInput {
    /** Cursor x in viewport coordinates. */
    cx: number;
    /** Cursor y in viewport coordinates. */
    cy: number;
    /** Measured tooltip width. */
    tipW: number;
    /** Measured tooltip height. */
    tipH: number;
    /** Gap between the cursor and the tooltip. */
    offset: number;
    /** Viewport width (window.innerWidth). */
    viewportW: number;
    /** Viewport height (window.innerHeight). */
    viewportH: number;
}

/**
 * Computes the fixed-position coordinates for the chart tooltip, flipping it to
 * the opposite side of the cursor when it would overflow the viewport and
 * clamping it to an 8px margin. The result is rounded to whole pixels so the
 * output is a stable fixed point: fractional `getBoundingClientRect` values
 * can't produce sub-pixel oscillation between renders.
 */
export function computeTooltipPosition({
    cx,
    cy,
    tipW,
    tipH,
    offset,
    viewportW,
    viewportH,
}: TooltipPositionInput): { x: number; y: number } {
    let x = cx + offset;
    let y = cy + offset;

    if (x + tipW > viewportW - 8) {
        x = cx - tipW - offset;
    }
    if (y + tipH > viewportH - 8) {
        y = cy - tipH - offset;
    }
    if (x < 8) {
        x = 8;
    }
    if (y < 8) {
        y = 8;
    }

    return { x: Math.round(x), y: Math.round(y) };
}
