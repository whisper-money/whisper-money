import { Audio, interpolate, Sequence, staticFile } from 'remotion';
import { FPS } from '../animation';
import { type Locale } from '../copy';
import { MUSIC, type Timeline } from '../timeline';

const FADE_IN = 36;
const FADE_OUT = 75;
/** The music ducks in from this many frames before a line and recovers over this many after it. */
const DUCK_ATTACK = 6;
const DUCK_RELEASE = 15;

/** Gain over the track's own level between lines, so the music keeps its build. */
const GAP_GAIN_DB = -6.5;
/** Music loudness under a line: about 19 dB below the mastered voice (≈ -16 LUFS). */
const UNDER_VOICE_LUFS = -35;
function musicLoudness(trackSeconds: number): number {
    return MUSIC.loudness.filter(([start]) => trackSeconds >= start).at(-1)?.[1] ?? MUSIC.loudness[0][1];
}

/** 1 while a line plays, easing to 0 in the gaps between lines. */
function duck(frame: number, { scenes }: Timeline): number {
    return Math.max(
        ...scenes.map(({ voiceFrom, voiceFrames }) =>
            interpolate(
                frame,
                [voiceFrom - DUCK_ATTACK, voiceFrom, voiceFrom + voiceFrames, voiceFrom + voiceFrames + DUCK_RELEASE],
                [0, 1, 1, 0],
                { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
            ),
        ),
    );
}

function musicVolume(frame: number, timeline: Timeline): number {
    const loudness = musicLoudness((frame - timeline.musicStartFrame) / FPS);
    const underVoiceDb = UNDER_VOICE_LUFS - loudness;
    const gainDb = interpolate(duck(frame, timeline), [0, 1], [GAP_GAIN_DB, underVoiceDb]);
    const musicFrom = Math.max(0, timeline.musicStartFrame);
    const fade = interpolate(
        frame,
        [musicFrom, musicFrom + FADE_IN, timeline.durationInFrames - FADE_OUT, timeline.durationInFrames - 1],
        [0, 1, 1, 0],
        { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
    );

    return Math.pow(10, gainDb / 20) * fade;
}

export function Soundtrack({ locale, timeline }: { locale: Locale; timeline: Timeline }) {
    const musicFrom = Math.max(0, timeline.musicStartFrame);

    return (
        <>
            <Sequence from={musicFrom} layout="none">
                <Audio
                    src={staticFile(MUSIC.src)}
                    trimBefore={Math.max(0, -timeline.musicStartFrame)}
                    volume={(frame) => musicVolume(frame + musicFrom, timeline)}
                />
            </Sequence>
            {timeline.scenes.map(({ id, voiceFrom }) => (
                <Sequence key={id} from={voiceFrom} layout="none">
                    <Audio src={staticFile(`voice/${locale}/${id}.mp3`)} />
                </Sequence>
            ))}
        </>
    );
}
