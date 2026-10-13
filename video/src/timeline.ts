import { FPS } from './animation';
import { type Locale } from './copy';
import voiceTimings from './voice-timings.json';
import { SCENE_IDS, type SceneId } from './voiceover';

export type VoiceWord = { word: string; start: number; end: number };

export type VoiceClip = { key: string; duration: number; words: VoiceWord[] };

export type VoiceTimings = Partial<Record<Locale, Partial<Record<SceneId, VoiceClip>>>>;

export type SceneTiming = {
    id: SceneId;
    /** First frame of the scene's sequence, where its transition-in starts. */
    from: number;
    durationInFrames: number;
    /** Composition frame where the scene's voice clip starts, and its length in frames. */
    voiceFrom: number;
    voiceFrames: number;
};

export type Timeline = { scenes: SceneTiming[]; durationInFrames: number; musicStartFrame: number };

export const TRANSITION_FRAMES = 12;
/** The voice starts once the transition-in has mostly played. */
const VOICE_DELAY = 9;
/** Silence between a clip's end and the start of the next transition. */
const VOICE_GAP = 6;
/** How long the last scene holds after its line, under the music fade-out. */
const END_HOLD = 30;

/** The length each scene was designed with; a scene only grows when its line needs more. */
const DESIGN_DURATIONS: Record<SceneId, number> = {
    hook: 90,
    noTracking: 110,
    spreadsheet: 170,
    turn: 75,
    sync: 165,
    dashboard: 150,
    budgets: 150,
    privacy: 110,
    comparison: 165,
    cta: 130,
};

/**
 * "The Light" (Alex Jones / Xander Jones) changes at fixed points: the build enters at 12.0 s and
 * the bigger lift at 36.0 s. The timeline makes them land on the start of the Turn ("there's a
 * better way") and Comparison scenes. `loudness` is the integrated loudness (LUFS) of each stretch
 * from its start in seconds, measured with ffmpeg's ebur128, which the soundtrack ducks against.
 */
export const MUSIC = {
    src: 'music/the-light.mp3',
    buildAt: 12,
    liftAt: 36,
    loudness: [
        [0, -25],
        [12, -20.7],
        [24, -18.2],
        [36, -14.8],
    ] as [number, number][],
};
const BUILD_SCENE: SceneId = 'turn';
const LIFT_SCENE: SceneId = 'comparison';

/** Frames into the Dashboard scene where every counter and sparkline has settled. */
const POSTER_OFFSET = 120;

export function voiceClip(locale: Locale, scene: SceneId): VoiceClip {
    const clip = (voiceTimings as VoiceTimings)[locale]?.[scene];

    if (clip === undefined) {
        throw new Error(`Missing voice clip ${locale}/${scene}: run \`bun run voice --locale=${locale}\``);
    }

    return clip;
}

/** Frame inside the scene where the voice starts saying `word`. */
export function wordFrame(locale: Locale, scene: SceneId, word: string): number {
    const spoken = voiceClip(locale, scene).words.find((candidate) => candidate.word.startsWith(word));

    if (spoken === undefined) {
        throw new Error(`The ${locale}/${scene} voice clip never says "${word}"`);
    }

    return VOICE_DELAY + Math.round(spoken.start * FPS);
}

function minimumDuration(locale: Locale, scene: SceneId, isLast: boolean): number {
    const voiceFrames = Math.ceil(voiceClip(locale, scene).duration * FPS);
    const after = isLast ? END_HOLD : VOICE_GAP + TRANSITION_FRAMES;

    return Math.max(DESIGN_DURATIONS[scene], VOICE_DELAY + voiceFrames + after);
}

/** Spreads the frames missing between the build and the lift over the scenes in between. */
function alignToMusic(locale: Locale, durations: number[]): number[] {
    const build = SCENE_IDS.indexOf(BUILD_SCENE);
    const lift = SCENE_IDS.indexOf(LIFT_SCENE);
    const between = durations.slice(build, lift);
    const span = between.reduce((total, duration) => total + duration, 0) - TRANSITION_FRAMES * between.length;
    const missing = (MUSIC.liftAt - MUSIC.buildAt) * FPS - span;

    if (missing < 0) {
        throw new Error(`The ${locale} scenes between the music build and lift are ${-missing} frames too long to line up`);
    }

    return durations.map((duration, index) => {
        if (index < build || index >= lift) {
            return duration;
        }

        const position = index - build;

        return duration + Math.floor(missing / between.length) + (position < missing % between.length ? 1 : 0);
    });
}

export function timeline(locale: Locale): Timeline {
    const durations = alignToMusic(locale, SCENE_IDS.map((id, index) => minimumDuration(locale, id, index === SCENE_IDS.length - 1)));
    let from = 0;

    const scenes = SCENE_IDS.map((id, index) => {
        const scene: SceneTiming = {
            id,
            from,
            durationInFrames: durations[index],
            voiceFrom: from + VOICE_DELAY,
            voiceFrames: Math.ceil(voiceClip(locale, id).duration * FPS),
        };
        from += durations[index] - TRANSITION_FRAMES;

        return scene;
    });
    const buildScene = scenes[SCENE_IDS.indexOf(BUILD_SCENE)];

    return {
        scenes,
        durationInFrames: from + TRANSITION_FRAMES,
        musicStartFrame: buildScene.from - MUSIC.buildAt * FPS,
    };
}

export function posterFrame(locale: Locale): number {
    const dashboard = timeline(locale).scenes.find((scene) => scene.id === 'dashboard');

    return (dashboard?.from ?? 0) + POSTER_OFFSET;
}
