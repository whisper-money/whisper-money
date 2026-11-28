// Turns rendered cards into PNGs.
//
// The cards are Blade views that PHP has already rendered to standalone HTML
// files; this only opens them and takes the pictures, in the Chromium
// lib/chromium.mjs starts.
//
// A whole month's worth of cards arrives in one manifest and shares one browser:
// starting Chromium costs more than the fifteen screenshots that follow, and the
// send window is an hour wide.
//
// Fonts come from Google Fonts over the network, so the run waits for them: a
// card that falls back to a system face is subtly but visibly wrong, and it is
// the one artefact users publish under their own name.
//
// Usage:
//   bun scripts/render-card.mjs <manifest.json>
//
// Manifest: [{ "html": "in.html", "png": "out.png", "width": 1080, "height": 1350 }]

import { readFile } from 'node:fs/promises';
import process from 'node:process';
import { launchChromium } from './lib/chromium.mjs';

const [manifestPath] = process.argv.slice(2);

if (!manifestPath) {
    console.error('usage: render-card.mjs <manifest.json>');
    process.exit(2);
}

const jobs = JSON.parse(await readFile(manifestPath, 'utf8'));

// Fonts are the only thing on the wire, and a slow CDN must not hang a queue
// worker: fall through to the fallback stack rather than wait forever.
const FONT_TIMEOUT_MS = 8000;

const browser = await launchChromium(['--font-render-hinting=none']);

try {
    const page = await browser.newPage({ deviceScaleFactor: 1 });

    for (const { html, png, width, height } of jobs) {
        await page.setViewportSize({ width: Number(width), height: Number(height) });
        await page.goto(`file://${html}`, { waitUntil: 'load' });

        try {
            await page.evaluate(() => document.fonts.ready);
            await page.waitForFunction(() => document.fonts.status === 'loaded', null, {
                timeout: FONT_TIMEOUT_MS,
            });
        } catch {
            // Rendering with the fallback face beats not rendering at all.
        }

        await page.screenshot({ path: png, type: 'png' });
    }
} finally {
    await browser.close();
}
