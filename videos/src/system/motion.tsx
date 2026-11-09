import { type CSSProperties, type ReactNode } from 'react';
import {
    Easing,
    interpolate,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { countUp } from './fixtures';

/** 0 → 1 spring that starts `delay` frames into the current sequence. */
export function useEntrance(delay = 0, damping = 200): number {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();

    return spring({ frame: frame - delay, fps, config: { damping } });
}

/**
 * A fixture whose figures count up from 0 over `frames`, easing out, starting
 * `from` frames into the sequence: feed it to a real component.
 */
export function useCountUp<T extends object>(
    value: T,
    from: number,
    frames = 45,
): T {
    const frame = useCurrentFrame();
    const progress = interpolate(frame, [from, from + frames], [0, 1], {
        easing: Easing.out(Easing.cubic),
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });

    return countUp(value, progress);
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
