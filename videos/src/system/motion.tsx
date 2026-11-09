import { type CSSProperties, type ReactNode } from 'react';
import { interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

/** 0 → 1 spring that starts `delay` frames into the current sequence. */
export function useEntrance(delay = 0, damping = 200): number {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();

    return spring({ frame: frame - delay, fps, config: { damping } });
}

/** 0 → 1 linear progress between two frames of the current sequence, clamped. */
export function useProgress(from: number, to: number): number {
    const frame = useCurrentFrame();

    return interpolate(frame, [from, to], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });
}

/** Fades and lifts its children in, `delay` frames into the sequence. */
export function Reveal({
    delay = 0,
    distance = 40,
    className,
    style,
    children,
}: {
    delay?: number;
    distance?: number;
    className?: string;
    style?: CSSProperties;
    children: ReactNode;
}) {
    const entrance = useEntrance(delay);

    return (
        <div
            className={className}
            style={{
                ...style,
                opacity: entrance,
                transform: `translateY(${(1 - entrance) * distance}px)`,
            }}
        >
            {children}
        </div>
    );
}
