import { type ReactNode } from 'react';
import { interpolate, useCurrentFrame } from 'remotion';
import { useEntrance } from './motion';

const EXIT_FRAMES = 10;

/** 0 → 1 while an overlay is on screen: springs in at `from`, fades out by `to`. */
function usePresence(from: number, to: number): number {
    const frame = useCurrentFrame();
    const entrance = useEntrance(from);
    const exit = interpolate(frame, [to - EXIT_FRAMES, to], [1, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });

    return frame < from ? 0 : Math.min(entrance, exit);
}

/**
 * A lower-third caption in screen space, over whatever the camera shows.
 * `from`/`to` are frames of the current sequence.
 */
export function Caption({
    from,
    to,
    title,
    children,
}: {
    from: number;
    to: number;
    title: string;
    children?: ReactNode;
}) {
    const presence = usePresence(from, to);

    if (presence === 0) {
        return null;
    }

    return (
        <div
            className="absolute inset-x-0 bottom-20 flex justify-center"
            style={{
                opacity: presence,
                transform: `translateY(${(1 - presence) * 30}px)`,
            }}
        >
            <div className="flex max-w-[1400px] flex-col items-center gap-2 rounded-2xl border border-white/10 bg-black/80 px-10 py-6 text-center shadow-2xl backdrop-blur">
                <p className="text-5xl font-semibold tracking-tight text-white">
                    {title}
                </p>
                {children && (
                    <p className="text-3xl text-neutral-300">{children}</p>
                )}
            </div>
        </div>
    );
}
