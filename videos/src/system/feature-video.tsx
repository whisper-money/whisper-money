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
}

/** Props every feature video composition takes. */
export interface FeatureVideoProps extends Record<string, unknown> {
    locale: VideoLocale;
}

const FADE_FRAMES = 12;
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
    children,
}: {
    durationInFrames: number;
    children: ReactNode;
}) {
    const frame = useCurrentFrame();
    const opacity = interpolate(
        frame,
        [0, FADE_FRAMES, durationInFrames - FADE_FRAMES, durationInFrames],
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
            volume={(frame) =>
                interpolate(
                    frame,
                    [
                        0,
                        MUSIC_FADE_IN,
                        durationInFrames - MUSIC_FADE_OUT,
                        durationInFrames,
                    ],
                    [0, 0.6, 0.6, 0],
                    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
                )
            }
        />
    );
}

/**
 * The template a feature video is: its scenes one after another on the dark
 * canvas, each fading through black, under background music.
 */
export function FeatureVideo({
    locale,
    currency,
    scenes,
    music = DEFAULT_MUSIC,
}: {
    locale: VideoLocale;
    currency: string;
    scenes: Scene[];
    music?: string | null;
}) {
    return (
        <VideoRoot locale={locale} currency={currency}>
            <Series>
                {scenes.map((scene) => (
                    <Series.Sequence
                        key={scene.id}
                        name={scene.id}
                        durationInFrames={scene.durationInFrames}
                    >
                        <SceneFade durationInFrames={scene.durationInFrames}>
                            {scene.content}
                        </SceneFade>
                    </Series.Sequence>
                ))}
            </Series>
            {music && <Music src={music} />}
        </VideoRoot>
    );
}
