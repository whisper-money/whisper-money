/**
 * Writes the background music feature videos use: a calm, original loop of
 * pads, a soft arpeggio and bass, synthesized from sine waves. Deterministic
 * and dependency free, so the track is ours to use anywhere.
 *
 *   node videos/scripts/generate-music.mjs [seconds]
 */
import { Buffer } from 'node:buffer';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const SAMPLE_RATE = 22050;
const SECONDS = Number(process.argv[2] ?? 32);
const BPM = 84;
const BEAT = 60 / BPM;
const BAR = BEAT * 4;

/** One chord per bar, as MIDI notes (Am7, Fmaj7, Cmaj7, G6). */
const CHORDS = [
    [57, 60, 64, 67],
    [53, 57, 60, 64],
    [48, 52, 55, 59],
    [55, 59, 62, 64],
];

const frequencyOf = (note) => 440 * 2 ** ((note - 69) / 12);

function pad(time, chord, timeInBar) {
    const swell =
        Math.min(1, timeInBar / 0.8) *
        Math.min(1, (BAR - timeInBar) / 0.4 + 0.35);

    return (
        chord.reduce((sum, note, index) => {
            const frequency = frequencyOf(note);
            // A slight detune per voice keeps the pad from sounding static.
            const detune = 1 + (index - 1.5) * 0.0015;

            return (
                sum +
                Math.sin(2 * Math.PI * frequency * detune * time) +
                0.3 * Math.sin(2 * Math.PI * frequency * 2 * time)
            );
        }, 0) *
        0.05 *
        swell
    );
}

function arpeggio(time, chord, timeInBar) {
    const step = Math.floor(timeInBar / (BEAT / 2));
    const sinceNote = timeInBar - step * (BEAT / 2);
    const note = chord[[0, 2, 1, 3, 2, 1, 3, 2][step % 8]] + 12;
    const frequency = frequencyOf(note);
    const envelope = Math.exp(-sinceNote * 5) * Math.min(1, sinceNote / 0.01);

    return (
        (Math.sin(2 * Math.PI * frequency * time) +
            0.2 * Math.sin(2 * Math.PI * frequency * 3 * time)) *
        envelope *
        0.09
    );
}

function bass(time, chord, timeInBar) {
    const sinceBeat = timeInBar % (BEAT * 2);
    const envelope = Math.exp(-sinceBeat * 1.5) * Math.min(1, sinceBeat / 0.02);

    return (
        Math.sin(2 * Math.PI * frequencyOf(chord[0] - 12) * time) *
        envelope *
        0.16
    );
}

const total = Math.round(SECONDS * SAMPLE_RATE);
const samples = new Float32Array(total);
let peak = 0;

for (let index = 0; index < total; index++) {
    const time = index / SAMPLE_RATE;
    const bar = Math.floor(time / BAR);
    const timeInBar = time - bar * BAR;
    const chord = CHORDS[bar % CHORDS.length];
    const sample =
        pad(time, chord, timeInBar) +
        arpeggio(time, chord, timeInBar) +
        bass(time, chord, timeInBar);

    samples[index] = sample;
    peak = Math.max(peak, Math.abs(sample));
}

// 16-bit mono PCM, normalized to -3 dBFS.
const gain = 0.7 / peak;
const wav = Buffer.alloc(44 + total * 2);
wav.write('RIFF', 0);
wav.writeUInt32LE(36 + total * 2, 4);
wav.write('WAVE', 8);
wav.write('fmt ', 12);
wav.writeUInt32LE(16, 16);
wav.writeUInt16LE(1, 20);
wav.writeUInt16LE(1, 22);
wav.writeUInt32LE(SAMPLE_RATE, 24);
wav.writeUInt32LE(SAMPLE_RATE * 2, 28);
wav.writeUInt16LE(2, 32);
wav.writeUInt16LE(16, 34);
wav.write('data', 36);
wav.writeUInt32LE(total * 2, 40);
samples.forEach((sample, index) => {
    wav.writeInt16LE(
        Math.round(Math.max(-1, Math.min(1, sample * gain)) * 32767),
        44 + index * 2,
    );
});

const target = join(
    dirname(fileURLToPath(import.meta.url)),
    '../public/music/background.wav',
);
writeFileSync(target, wav);
console.log(`Wrote ${target} (${SECONDS}s)`);
