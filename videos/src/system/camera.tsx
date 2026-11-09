import { type ReactNode } from 'react';
import {
    AbsoluteFill,
    Easing,
    interpolate,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';

/** Where the camera looks at a frame: a point of the content, and the zoom. */
export interface CameraShot {
    frame: number;
    /** Content x (px) shown at the center of the screen. */
    x: number;
    /** Content y (px) shown at the center of the screen. */
    y: number;
    scale: number;
}

function along(frame: number, shots: CameraShot[], pick: keyof CameraShot) {
    return interpolate(
        frame,
        shots.map((shot) => shot.frame),
        shots.map((shot) => shot[pick]),
        {
            easing: Easing.inOut(Easing.cubic),
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
        },
    );
}

/**
 * Zooms and pans over its content, easing from shot to shot. Content is laid
 * out from the top-left corner, so a shot names content coordinates; give at
 * least one shot, ordered by frame.
 */
export function Camera({
    shots,
    children,
}: {
    shots: CameraShot[];
    children: ReactNode;
}) {
    const frame = useCurrentFrame();
    const { width, height } = useVideoConfig();
    // interpolate() needs two points; a single shot holds still.
    const ordered =
        shots.length > 1
            ? shots
            : [...shots, { ...shots[0], frame: shots[0].frame + 1 }];
    const scale = along(frame, ordered, 'scale');
    const x = width / 2 - along(frame, ordered, 'x') * scale;
    const y = height / 2 - along(frame, ordered, 'y') * scale;

    return (
        <AbsoluteFill style={{ overflow: 'hidden' }}>
            <div
                style={{
                    position: 'absolute',
                    left: 0,
                    top: 0,
                    transformOrigin: '0 0',
                    transform: `translate(${x}px, ${y}px) scale(${scale})`,
                }}
            >
                {children}
            </div>
        </AbsoluteFill>
    );
}
