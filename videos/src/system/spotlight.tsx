import { type ReactNode } from 'react';
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from 'remotion';
import { type Scene } from './feature-video';
import { useEntrance } from './motion';

const EXIT_FRAMES = 8;

interface SpotlightProps {
    /** Short headline over the component. */
    title: string;
    /** One line under the headline. */
    subtitle?: string;
    /** A short pill under the component, from `note.at`. */
    note?: { at: number; text: string };
    /** Layout width of the component, before the zoom. */
    width: number;
    /** CSS zoom: the component lays out at `width` and is drawn this much bigger. */
    zoom: number;
    durationInFrames: number;
    children: ReactNode;
}

/** 1 while on screen, dropping to 0 over the scene's last frames. */
function useExit(durationInFrames: number): number {
    const frame = useCurrentFrame();

    return interpolate(
        frame,
        [durationInFrames - EXIT_FRAMES, durationInFrames],
        [1, 0],
        {
            easing: Easing.in(Easing.cubic),
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
        },
    );
}

/**
 * One component alone in the middle of the dark canvas: it springs in under
 * its headline, drifts slowly closer while it plays, and leaves with a quick
 * push forward so the next scene can cut straight in.
 */
export function Spotlight({
    title,
    subtitle,
    note,
    width,
    zoom,
    durationInFrames,
    children,
}: SpotlightProps) {
    const frame = useCurrentFrame();
    const entrance = useEntrance(0, 18);
    const heading = useEntrance(4);
    const noteIn = useEntrance(note?.at ?? 0, 14);
    const exit = useExit(durationInFrames);
    const drift = interpolate(frame, [0, durationInFrames], [0.97, 1.02]);
    const glow = interpolate(frame, [0, durationInFrames], [-8, 8]);

    return (
        <AbsoluteFill
            className="items-center justify-center gap-10"
            style={{
                background: `radial-gradient(60% 55% at ${50 + glow}% 55%, rgba(74, 222, 128, 0.09), transparent 70%)`,
                opacity: exit,
                transform: `scale(${1 + (1 - exit) * 0.08})`,
            }}
        >
            <div
                className="flex flex-col items-center gap-3 text-center"
                style={{
                    opacity: heading,
                    transform: `translateY(${(1 - heading) * -24}px)`,
                }}
            >
                <h2 className="text-6xl font-semibold tracking-tight">
                    {title}
                </h2>
                {subtitle && (
                    <p className="text-3xl text-muted-foreground">{subtitle}</p>
                )}
            </div>
            <div
                style={{
                    width,
                    zoom,
                    opacity: Math.min(1, entrance * 1.5),
                    transform: `translateY(${(1 - entrance) * 60}px) scale(${(0.9 + entrance * 0.1) * drift})`,
                }}
            >
                {children}
            </div>
            {note && (
                <p
                    className="rounded-full border border-green-400/40 bg-green-400/10 px-8 py-3 text-3xl font-medium text-green-300"
                    style={{
                        opacity: frame < note.at ? 0 : noteIn,
                        transform: `scale(${0.8 + noteIn * 0.2})`,
                    }}
                >
                    {note.text}
                </p>
            )}
        </AbsoluteFill>
    );
}

/**
 * A scene that spotlights one component. It animates its own way in and out,
 * so the template does not fade it through black.
 */
export function spotlightScene({
    id,
    durationInFrames,
    content,
    ...props
}: Omit<SpotlightProps, 'children'> & {
    id: string;
    /** The component to spotlight. */
    content: ReactNode;
}): Scene {
    return {
        id,
        durationInFrames,
        fade: 0,
        content: (
            <Spotlight durationInFrames={durationInFrames} {...props}>
                {content}
            </Spotlight>
        ),
    };
}
