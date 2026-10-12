import { Easing, interpolate, spring } from 'remotion';

export const FPS = 30;

/** Soft, overshoot-free entrance used by almost every element. */
export function enter(frame: number, delay = 0, durationInFrames = 24): number {
    return spring({
        frame: frame - delay,
        fps: FPS,
        config: { damping: 200 },
        durationInFrames,
    });
}

/** Slightly bouncy entrance for badges, checks and buttons. */
export function pop(frame: number, delay = 0): number {
    return spring({
        frame: frame - delay,
        fps: FPS,
        config: { damping: 12, stiffness: 180, mass: 0.6 },
    });
}

export function progress(
    frame: number,
    start: number,
    end: number,
    easing: (t: number) => number = Easing.bezier(0.33, 1, 0.68, 1),
): number {
    return interpolate(frame, [start, end], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing,
    });
}

export function rise(value: number, distance = 24): string {
    return `translateY(${(1 - value) * distance}px)`;
}
