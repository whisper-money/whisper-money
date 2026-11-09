import { type ReactNode } from 'react';
import {
    AbsoluteFill,
    Html5Audio,
    interpolate,
    Series,
    staticFile,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { type VideoLocale } from './locale';
import { seconds } from './theme';
import { VideoRoot } from './video-root';

/** One stretch of a feature video; frames inside it count from 0. */
export interface Scene {
    id: string;
    durationInFrames: number;
    content: ReactNode;
    /** Frames it fades through black at each end; 0 for scenes that animate their own way in and out. */
    fade?: number;
}

/** Props every feature video composition takes. */
export interface FeatureVideoProps extends Record<string, unknown> {
    locale: VideoLocale;
}

const FADE_FRAMES = 12;
/** Music sits under the visuals rather than over them. */
const MUSIC_VOLUME = 0.6;
const MUSIC_FADE_IN = seconds(1);
const MUSIC_FADE_OUT = seconds(2);
/** Background music, from videos/public. */
export const DEFAULT_MUSIC = 'music/background.wav';

/** Total length of a list of scenes, for the <Composition>. */
export function durationOf(scenes: Scene[]): number {
    return scenes.reduce((total, scene) => total + scene.durationInFrames, 0);
}

/** Fades a scene in from black and back out to it. */
function SceneFade({
    durationInFrames,
    fade,
    children,
}: {
    durationInFrames: number;
    fade: number;
    children: ReactNode;
}) {
    const frame = useCurrentFrame();
    const opacity =
        fade === 0
            ? 1
            : interpolate(
                  frame,
                  [0, fade, durationInFrames - fade, durationInFrames],
                  [0, 1, 1, 0],
                  { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
              );

    return <AbsoluteFill style={{ opacity }}>{children}</AbsoluteFill>;
}

/** Background music that fades in at the start and out at the end. */
function Music({ src }: { src: string }) {
    const { durationInFrames } = useVideoConfig();

    return (
        <Html5Audio
            src={staticFile(src)}
            // The generated track is 32s: a longer video loops it.
            loop
            volume={(frame) =>
                interpolate(
                    frame,
                    [
                        0,
                        MUSIC_FADE_IN,
                        durationInFrames - MUSIC_FADE_OUT,
                        durationInFrames,
                    ],
                    [0, MUSIC_VOLUME, MUSIC_VOLUME, 0],
                    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
                )
            }
        />
    );
}

/** The scenes one after another, built once the locale is applied. */
function SceneSeries({ scenes }: { scenes: () => Scene[] }) {
    return (
        <Series>
            {scenes().map((scene) => (
                <Series.Sequence
                    key={scene.id}
                    name={scene.id}
                    durationInFrames={scene.durationInFrames}
                >
                    <SceneFade
                        durationInFrames={scene.durationInFrames}
                        fade={scene.fade ?? FADE_FRAMES}
                    >
                        {scene.content}
                    </SceneFade>
                </Series.Sequence>
            ))}
        </Series>
    );
}

/**
 * The template a feature video is: its scenes one after another on the dark
 * canvas, each fading through black unless it brings its own transition,
 * under background music. `scenes` is called inside <VideoRoot>, so copy read
 * with `__()` while building them is already in the video's language.
 */
export function FeatureVideo({
    locale,
    currency,
    scenes,
    music = DEFAULT_MUSIC,
}: {
    locale: VideoLocale;
    currency: string;
    scenes: () => Scene[];
    music?: string | null;
}) {
    return (
        <VideoRoot locale={locale} currency={currency}>
            <SceneSeries scenes={scenes} />
            {music && <Music src={music} />}
        </VideoRoot>
    );
}
