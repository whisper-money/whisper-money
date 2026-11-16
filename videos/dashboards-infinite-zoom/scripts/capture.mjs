// Captures the real dashboard and widgets of the local promo accounts
// (promo-b-en / promo-b-es) that the film zooms through.
//
// Prerequisites: the root dependencies installed (Playwright comes from the
// root package.json), `composer run dev` running in this checkout, and the two
// accounts seeded with their widgets (see REVIEW.md, "How it was made").
//
// Usage, from the repository root:
//   APP_BASE_URL=http://127.0.0.1:<port> node videos/dashboards-infinite-zoom/scripts/capture.mjs

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const BASE_URL = (process.env.APP_BASE_URL ?? '').replace(/\/$/, '');
// The local promo accounts' password, set when they were seeded.
const PASSWORD = process.env.PROMO_PASSWORD ?? '';
const LOCALES = (process.env.LOCALES ?? 'en,es').split(',');
const OUT = join(
    dirname(fileURLToPath(import.meta.url)),
    '..',
    'assets',
    'captures',
);

/** The widgets in the order the film meets them, by their dashboard title. */
const WIDGETS = ['takeaway', 'car', 'kids', 'groceries'];

if (BASE_URL === '' || PASSWORD === '') {
    console.error(
        "Set APP_BASE_URL to the dev server of this checkout and PROMO_PASSWORD to the promo accounts' password.",
    );
    process.exit(1);
}

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 20000,
    });
}

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('[data-widget^="user:"] .recharts-surface', {
        timeout: 30000,
    });
    // Keep the pointer off the charts, and hide any tooltip a chart opens on
    // its own, so a shot shows the widget at rest.
    await page.mouse.move(1, 1);
    await page.addStyleTag({
        content:
            '.recharts-tooltip-wrapper, .recharts-tooltip-cursor { display: none !important; }',
    });
    // Recharts animates bars and areas in; wait for them to land.
    await page.waitForTimeout(2500);
}

// The bundled headless shell is a slow download on a fresh machine; the
// system Chrome, still headless, is already there.
const browser = await chromium
    .launch({ headless: true })
    .catch(() => chromium.launch({ headless: true, channel: 'chrome' }));

for (const locale of LOCALES) {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 810 },
        deviceScaleFactor: 3,
        locale: locale === 'es' ? 'es-ES' : 'en-GB',
        timezoneId: 'Europe/Madrid',
        colorScheme: 'light',
    });
    const page = await context.newPage();
    await login(page, `promo-b-${locale}@whisper.local`);
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'domcontentloaded' });
    await settle(page);

    const dir = join(OUT, locale);
    mkdirSync(dir, { recursive: true });

    await page.screenshot({ path: join(dir, 'dashboard.png') });

    const cells = page.locator('[data-widget^="user:"]');
    const count = await cells.count();
    for (let index = 0; index < count && index < WIDGETS.length; index++) {
        await cells.nth(index).screenshot({
            path: join(dir, `widget-${WIDGETS[index]}.png`),
        });
    }

    // Where every widget sits on the dashboard and where its marks sit inside
    // it, in CSS pixels, so the film's camera can aim at a real bar.
    const layout = await page.evaluate((names) => {
        const box = (element, origin) => {
            const rect = element.getBoundingClientRect();
            return {
                x: rect.x - origin.x,
                y: rect.y - origin.y,
                width: rect.width,
                height: rect.height,
            };
        };
        const zero = { x: 0, y: 0 };
        const widgets = {};
        document
            .querySelectorAll('[data-widget^="user:"]')
            .forEach((cell, index) => {
                if (index >= names.length) {
                    return;
                }
                const origin = cell.getBoundingClientRect();
                widgets[names[index]] = {
                    box: box(cell, zero),
                    bars: [
                        ...cell.querySelectorAll(
                            '.recharts-bar-rectangle path',
                        ),
                    ].map((bar) => box(bar, origin)),
                    areas: [
                        ...cell.querySelectorAll('.recharts-area-area'),
                    ].map((area) => box(area, origin)),
                };
            });
        return {
            viewport: { width: window.innerWidth, height: window.innerHeight },
            widgets,
        };
    }, WIDGETS);
    writeFileSync(
        join(dir, 'layout.json'),
        `${JSON.stringify(layout, null, 2)}\n`,
    );

    console.log(`  ${locale}: dashboard + ${count} widgets`);
    await context.close();
}

await browser.close();
