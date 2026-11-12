import { type CSSProperties } from 'react';

/**
 * How far `progress` is through a step of a scroll-linked animation that
 * starts at `start` and lasts `span`, from 0 to 1.
 */
export function stepProgress(
    progress: number,
    start: number,
    span: number,
): number {
    return Math.min(1, Math.max(0, (progress - start) / span));
}

/** Fades a step in while lifting it `distance` pixels into place. */
export function riseStyle(revealed: number, distance: number): CSSProperties {
    return {
        opacity: revealed,
        transform: `translateY(${(1 - revealed) * distance}px)`,
    };
}
