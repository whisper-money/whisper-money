/**
 * Writes the film's three music layers, one per person, as WAV files that
 * share one tempo and chord grid so they can enter one by one and still play
 * together: a warm pad (the freelancer), a plucked arpeggio (the family) and
 * a soft kick, hat and bass (the traveller). Synthesized from sine waves,
 * deterministic and dependency free, so the music is ours to use anywhere.
 *
 *   node videos/dashboards-one-per-person/scripts/music.mjs <out-dir> [seconds]
 *
 * Encode each to MP3 for assets/music with ffmpeg afterwards.
 */
import { Buffer } from 'node:buffer';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const SAMPLE_RATE = 44100;
const OUT = process.argv[2] ?? '.';
const SECONDS = Number(process.argv[3] ?? 36);
const BPM = 96;
const BEAT = 60 / BPM;
const BAR = BEAT * 4;

/** One chord per bar, as MIDI notes (Dmaj7, Bm7, Gmaj7, A6). */
const CHORDS = [
    [50, 54, 57, 61],
    [47, 50, 54, 57],
    [43, 47, 50, 54],
    [45, 49, 52, 54],
];

const frequencyOf = (note) => 440 * 2 ** ((note - 69) / 12);

/** A tiny deterministic noise source for the hat. */
function noise(index) {
    const value = Math.sin(index * 12.9898) * 43758.5453;

    return (value - Math.floor(value)) * 2 - 1;
}

function pad(time, chord, timeInBar) {
    const swell =
        Math.min(1, timeInBar / 0.6) *
        Math.min(1, (BAR - timeInBar) / 0.3 + 0.4);

    return (
        chord.reduce((sum, note, index) => {
            const frequency = frequencyOf(note + 12);
            const detune = 1 + (index - 1.5) * 0.0018;

            return (
                sum +
                Math.sin(2 * Math.PI * frequency * detune * time) +
                0.25 * Math.sin(2 * Math.PI * frequency * 2 * time)
            );
        }, 0) *
        0.06 *
        swell
    );
}

function arpeggio(time, chord, timeInBar) {
    const step = Math.floor(timeInBar / (BEAT / 2));
    const sinceNote = timeInBar - step * (BEAT / 2);
    const note = chord[[0, 1, 2, 3, 2, 1, 3, 1][step % 8]] + 24;
    const frequency = frequencyOf(note);
    const envelope = Math.exp(-sinceNote * 6) * Math.min(1, sinceNote / 0.005);

    return (
        (Math.sin(2 * Math.PI * frequency * time) +
            0.3 * Math.sin(2 * Math.PI * frequency * 2 * time) +
            0.1 * Math.sin(2 * Math.PI * frequency * 3 * time)) *
        envelope *
        0.12
    );
}

function rhythm(time, chord, timeInBar, index) {
    const sinceBeat = timeInBar % BEAT;
    const kickPitch = 50 + 90 * Math.exp(-sinceBeat * 30);
    const kick =
        Math.sin(2 * Math.PI * kickPitch * sinceBeat) *
        Math.exp(-sinceBeat * 9) *
        0.35;
    const sinceHalf = timeInBar % (BEAT / 2);
    const offbeat = Math.floor(timeInBar / (BEAT / 2)) % 2 === 1;
    const hat = offbeat ? noise(index) * Math.exp(-sinceHalf * 45) * 0.05 : 0;
    const sinceBass = timeInBar % (BEAT * 2);
    const bass =
        Math.sin(2 * Math.PI * frequencyOf(chord[0] - 12) * time) *
        Math.exp(-sinceBass * 1.2) *
        Math.min(1, sinceBass / 0.02) *
        0.22;

    return kick + hat + bass;
}

function writeWav(name, voice) {
    const total = Math.round(SECONDS * SAMPLE_RATE);
    const samples = new Float32Array(total);
    let peak = 0;

    for (let index = 0; index < total; index++) {
        const time = index / SAMPLE_RATE;
        const bar = Math.floor(time / BAR);
        const timeInBar = time - bar * BAR;
        const sample = voice(
            time,
            CHORDS[bar % CHORDS.length],
            timeInBar,
            index,
        );

        samples[index] = sample;
        peak = Math.max(peak, Math.abs(sample));
    }

    // 16-bit mono PCM, normalized to -6 dBFS so the three layers sum cleanly.
    const gain = 0.5 / peak;
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

    const target = join(OUT, `${name}.wav`);
    writeFileSync(target, wav);
    console.log(`Wrote ${target} (${SECONDS}s)`);
}

writeWav('layer-pad', pad);
writeWav('layer-arpeggio', arpeggio);
writeWav('layer-rhythm', rhythm);
