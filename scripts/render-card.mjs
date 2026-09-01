// Turns one rendered card into a PNG.
//
// The card is a Blade view that PHP has already rendered to a standalone HTML
// file; this only opens it and takes the picture. Playwright is used rather than
// a second browser stack because it is already a dependency of the Pest browser
// suite, so the image carries the same Chromium the tests run on.
//
// Fonts come from Google Fonts over the network, so the run waits for them: a
// card that falls back to a system face is subtly but visibly wrong, and it is
// the one artefact users publish under their own name.
//
// Usage:
//   bun scripts/render-card.mjs <input.html> <output.png> <width> <height>

import { chromium } from 'playwright';
import process from 'node:process';

const [input, output, width, height] = process.argv.slice(2);

if (!input || !output || !width || !height) {
    console.error('usage: render-card.mjs <input.html> <output.png> <width> <height>');
    process.exit(2);
}

// Fonts are the only thing on the wire, and a slow CDN must not hang a queue
// worker: fall through to the fallback stack rather than wait forever.
const FONT_TIMEOUT_MS = 8000;

// `channel: 'chromium'` asks for the full browser rather than the headless
// shell, which is a separate download that `playwright install chromium` does
// not always place — and a card is worth one extra binary in the image.
const browser = await chromium.launch({
    channel: 'chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
});

try {
    const page = await browser.newPage({
        viewport: { width: Number(width), height: Number(height) },
        deviceScaleFactor: 1,
    });

    await page.goto(`file://${input}`, { waitUntil: 'load' });

    try {
        await page.evaluate(() => document.fonts.ready);
        await page.waitForFunction(() => document.fonts.status === 'loaded', null, {
            timeout: FONT_TIMEOUT_MS,
        });
    } catch {
        // Rendering with the fallback face beats not rendering at all.
    }

    await page.screenshot({ path: output, type: 'png' });
} finally {
    await browser.close();
}
