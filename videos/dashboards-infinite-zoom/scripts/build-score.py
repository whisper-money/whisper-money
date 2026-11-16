"""Builds assets/audio/score.m4a: the MusicGen pad plus a kick pulse that speeds
up on every dive and stops on the pull-back.

The pad (assets/audio/pad-musicgen.flac) was generated on this machine with
facebook/musicgen-small (seed 7, prompt in REVIEW.md). The kick is the attack
of the bundled impact-bass-1 sample (assets/sfx/). Nothing is synthesised.

Usage (needs numpy, soundfile and ffmpeg): python3 scripts/build-score.py
"""

import subprocess
from pathlib import Path

import numpy as np
import soundfile as sf

ROOT = Path(__file__).resolve().parent.parent
RATE = 44100
LENGTH = 33.5
RELEASE = 19.8  # the pull-back: the pulse stops here


def load(path: Path) -> np.ndarray:
    data, rate = sf.read(path, always_2d=True)
    mono = data.mean(axis=1)
    if rate != RATE:
        positions = np.arange(0, len(mono), rate / RATE)
        mono = np.interp(positions, np.arange(len(mono)), mono)
    return mono


def beats(start: float, end: float, bpm: float) -> list[float]:
    step = 60 / bpm
    count = int((end - start) / step + 1e-9)
    return [start + index * step for index in range(count)]


def accelerate(start: float, gaps: list[float]) -> list[float]:
    times, t = [], start
    for gap in gaps:
        times.append(t)
        t += gap
    return times


# Every kick time, in seconds. A cycle's grid starts on its first frame.
KICKS = (
    accelerate(0.0, [0.65, 0.45, 0.3, 0.2])  # hook dive, 0.0-1.6
    + beats(1.6, 8.1, 92)  # cycle 1
    + accelerate(8.1, [0.36, 0.26, 0.18])  # dive into the Friday bar
    + beats(9.0, 14.2, 112)  # cycle 2
    + accelerate(14.2, [0.3, 0.22, 0.16, 0.12])  # dive into the car loan
    + beats(15.0, RELEASE - 0.05, 132)  # cycle 3
)

total = int(LENGTH * RATE)

# Pad: crossfade-loop the 28 s clip to the film's length.
pad = load(ROOT / "assets/audio/pad-musicgen.flac")
fade = int(3 * RATE)
looped = pad.copy()
while len(looped) < total:
    ramp = np.linspace(0, 1, fade)
    head = looped[:-fade]
    joint = looped[-fade:] * (1 - ramp) + pad[:fade] * ramp
    looped = np.concatenate([head, joint, pad[fade:]])
looped = looped[:total]
t = np.arange(total) / RATE
pad_gain = np.where(t < RELEASE, 0.55, 1.0)
pad_gain = np.convolve(pad_gain, np.ones(int(0.8 * RATE)) / int(0.8 * RATE), mode="same")
pad_gain *= np.clip(t / 0.4, 0, 1) * np.clip((LENGTH - t) / 1.5, 0, 1)
pad_mix = looped / (np.abs(looped).max() + 1e-9) * 0.5 * pad_gain

# Kick: the first 0.3 s of impact-bass-1, with a short fade so it does not click.
impact = load(ROOT / "assets/sfx/impact-bass-1.mp3")
kick = impact[: int(0.3 * RATE)].copy()
kick *= np.linspace(1, 0, len(kick)) ** 1.5
kick /= np.abs(kick).max()
kick_mix = np.zeros(total)
for time in KICKS:
    start = int(time * RATE)
    end = min(start + len(kick), total)
    kick_mix[start:end] += kick[: end - start] * 0.42

score = pad_mix + kick_mix
score /= max(1.0, np.abs(score).max() / 0.89)
wav = ROOT / "assets/audio/score.wav"
sf.write(wav, np.stack([score, score], axis=1), RATE)
subprocess.run(
    ["ffmpeg", "-loglevel", "error", "-y", "-i", str(wav), "-c:a", "aac", "-b:a", "192k", str(ROOT / "assets/audio/score.m4a")],
    check=True,
)
wav.unlink()
print(f"score.m4a: {LENGTH} s, {len(KICKS)} kicks")
