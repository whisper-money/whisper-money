// Generates the voice-over with the cloned ElevenLabs voice: one mastered clip per scene and
// locale in public/voice/<locale>/<scene>.mp3, plus their durations and word timings in
// src/voice-timings.json, which the composition reads to time every scene.
// A clip whose text and voice settings haven't changed is skipped.
//
// Usage (ELEVENLABS_API_KEY must be in the environment):
//   bun run voice                  every locale
//   bun run voice --locale=es      one locale
//   bun run voice --force          regenerate even the unchanged clips
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { type Locale } from '../src/copy';
import { type VoiceTimings, type VoiceWord } from '../src/timeline';
import { SCENE_IDS, spokenLine } from '../src/voiceover';

const VOICE = {
    voiceId: 'AT196KaGpG8hs1Ibkqfh',
    model: 'eleven_v4',
    seed: 1987,
    settings: { stability: 0.5 },
    outputFormat: 'mp3_44100_128',
};
// Same gain on every clip so sentences keep their relative level, then a limiter at -1.5 dBTP.
const MASTER_GAIN_DB = 5;
const MASTER_LIMIT_DB = -1.5;
const MASTER_FILTER = `volume=${MASTER_GAIN_DB}dB,alimiter=limit=${Math.pow(10, MASTER_LIMIT_DB / 20).toFixed(4)}:attack=4:release=60:level=disabled`;

const ROOT = path.resolve(import.meta.dir, '..');
const TIMINGS_FILE = path.join(ROOT, 'src/voice-timings.json');

type Alignment = {
    characters: string[];
    character_start_times_seconds: number[];
    character_end_times_seconds: number[];
};

const option = (name: string): string | true | undefined => {
    const arg = process.argv.find((value) => value === `--${name}` || value.startsWith(`--${name}=`));

    return arg?.includes('=') ? arg.split('=')[1] : arg ? true : undefined;
};

const apiKey = process.env.ELEVENLABS_API_KEY;

if (!apiKey) {
    console.error('ELEVENLABS_API_KEY is missing from the environment.');
    process.exit(1);
}

async function synthesize(locale: Locale, text: string, previous?: string, next?: string) {
    const url = new URL('https://api.elevenlabs.io/v1/text-to-dialogue/with-timestamps');
    url.searchParams.set('output_format', VOICE.outputFormat);
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'xi-api-key': apiKey as string, 'Content-Type': 'application/json' },
        body: JSON.stringify({
            inputs: [{ text, voice_id: VOICE.voiceId }],
            model_id: VOICE.model,
            settings: VOICE.settings,
            language_code: locale,
            seed: VOICE.seed,
            previous_text: previous?.slice(-100),
            future_text: next?.slice(0, 100),
        }),
    });

    if (!response.ok) {
        throw new Error(`ElevenLabs ${response.status}: ${await response.text()}`);
    }

    return (await response.json()) as { audio_base64: string; alignment: Alignment };
}

function master(raw: Buffer, destination: string): number {
    const workDir = mkdtempSync(path.join(tmpdir(), 'voice-'));
    const source = path.join(workDir, 'raw.mp3');

    try {
        writeFileSync(source, raw);
        execFileSync('ffmpeg', [
            ...['-hide_banner', '-loglevel', 'error', '-y', '-i', source],
            ...['-af', MASTER_FILTER],
            ...['-ar', '44100', '-ac', '1', '-c:a', 'libmp3lame', '-b:a', '128k', destination],
        ]);
    } finally {
        rmSync(workDir, { recursive: true });
    }

    const duration = execFileSync('ffprobe', [
        ...['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', destination],
    ]);

    return Number(duration.toString().trim());
}

function words(alignment: Alignment): VoiceWord[] {
    const result: VoiceWord[] = [];
    let current: VoiceWord | null = null;

    alignment.characters.forEach((character, index) => {
        if (/\s/.test(character)) {
            current = null;

            return;
        }

        if (current === null) {
            current = { word: '', start: alignment.character_start_times_seconds[index], end: 0 };
            result.push(current);
        }

        current.word += character;
        current.end = alignment.character_end_times_seconds[index];
    });

    return result.map(({ word, start, end }) => ({ word, start: round(start), end: round(end) }));
}

const round = (seconds: number): number => Math.round(seconds * 1000) / 1000;

const timings: VoiceTimings = existsSync(TIMINGS_FILE) ? JSON.parse(readFileSync(TIMINGS_FILE, 'utf8')) : {};
const onlyLocale = option('locale');
const LOCALES: Locale[] = ['en', 'es'];
const locales = typeof onlyLocale === 'string' ? LOCALES.filter((locale) => locale === onlyLocale) : LOCALES;

if (locales.length === 0) {
    console.error(`Unknown locale "${onlyLocale}", expected one of: ${LOCALES.join(', ')}`);
    process.exit(1);
}

for (const locale of locales) {
    mkdirSync(path.join(ROOT, 'public/voice', locale), { recursive: true });
    timings[locale] ??= {};

    for (const [index, scene] of SCENE_IDS.entries()) {
        const text = spokenLine(locale, scene);
        const previous = index > 0 ? spokenLine(locale, SCENE_IDS[index - 1]) : undefined;
        const next = index < SCENE_IDS.length - 1 ? spokenLine(locale, SCENE_IDS[index + 1]) : undefined;
        // Everything that shapes the clip: the line, its neighbours (they set the delivery), voice and mastering.
        const key = createHash('sha1')
            .update(JSON.stringify([text, previous, next, locale, VOICE, MASTER_FILTER]))
            .digest('hex')
            .slice(0, 12);
        const clip = path.join(ROOT, 'public/voice', locale, `${scene}.mp3`);

        if (!option('force') && timings[locale]?.[scene]?.key === key && existsSync(clip)) {
            console.log(`= ${locale}/${scene} unchanged`);
            continue;
        }

        const response = await synthesize(locale, text, previous, next);
        const duration = master(Buffer.from(response.audio_base64, 'base64'), clip);
        const spokenWords = words(response.alignment);
        timings[locale][scene] = { key, duration: round(duration), words: spokenWords };
        console.log(`✓ ${locale}/${scene} ${duration.toFixed(2)}s  ${text}`);
    }
}

writeFileSync(TIMINGS_FILE, `${JSON.stringify(timings, null, 4)}\n`);
