import { interpolate, useCurrentFrame } from 'remotion';
import { useVideo } from '../context';

/** The Whisper Money bird (favicon paths), optionally drawn stroke by stroke. */
const PATHS = [
    'M16 7h.01',
    'M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20',
    'm20 7 2 .5-2 .5',
    'M10 18v3',
    'M14 17.75V21',
    'M7 18a6 6 0 0 0 3.84-10.61',
];

export function Logo({
    size,
    drawFrom,
    color,
}: {
    size: number;
    drawFrom?: number;
    color?: string;
}) {
    const frame = useCurrentFrame();
    const { theme } = useVideo();

    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none">
            {PATHS.map((d, index) => {
                const drawn =
                    drawFrom === undefined
                        ? 1
                        : interpolate(frame, [drawFrom + index * 4, drawFrom + index * 4 + 24], [0, 1], {
                              extrapolateLeft: 'clamp',
                              extrapolateRight: 'clamp',
                          });

                return (
                    <path
                        key={d}
                        d={d}
                        pathLength={1}
                        stroke={color ?? theme.text}
                        strokeWidth={2}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeDasharray="1 1"
                        strokeDashoffset={1 - drawn}
                    />
                );
            })}
        </svg>
    );
}

export function LogoTile({ size }: { size: number }) {
    const { theme } = useVideo();

    return (
        <div
            style={{
                width: size,
                height: size,
                borderRadius: size * 0.22,
                background: theme.text,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}
        >
            <Logo size={size * 0.64} color={theme.background} />
        </div>
    );
}
