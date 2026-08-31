// What the documentation's screenshots and videos both need: the account they
// are recorded against, the dev server they talk to, and signing in to it.
//
// Both are always recorded in English, whatever language the page embedding them
// is written in, and both run against `demo:reset` data so the figures are
// plausible and the same every time.

import { execFileSync } from 'node:child_process';
import process from 'node:process';
import { chromium } from 'playwright';

export const OUT_DIR = 'public/docs/documentation';
export const EMAIL = 'docs-media@example.test';
export const PASSWORD = 'password123456';
export const HEADLESS = process.env.HEADLESS !== '0';

/**
 * `composer run dev` serves on a random port behind a portless hostname that is
 * not resolvable from here, so talk to `artisan serve` directly.
 *
 * The port has to belong to *this* checkout: several worktrees each run their own
 * dev server, and recording another branch's app would produce media that looks
 * right and documents code that is not there.
 */
export function detectBaseUrl() {
    if (process.env.APP_BASE_URL) {
        return process.env.APP_BASE_URL.replace(/\/$/, '');
    }

    const port = execFileSync(
        'bash',
        [
            '-c',
            `for pid in $(pgrep -f 'artisan serve --port=[0-9]'); do
                cwd=$(lsof -p "$pid" -a -d cwd -Fn 2>/dev/null | grep '^n' | cut -c2-)
                if [ "$cwd" = "$PWD" ]; then
                    ps -o command= -p "$pid" | grep -oE -- '--port=[0-9]+' | grep -oE '[0-9]+$'
                    break
                fi
            done`,
        ],
        { encoding: 'utf8' },
    ).trim();

    if (!port) {
        throw new Error(
            'No dev server for this checkout. Start it with `composer run dev` here, or pass APP_BASE_URL.',
        );
    }

    return `http://127.0.0.1:${port}`;
}

export function artisan(args) {
    return execFileSync('php', ['artisan', ...args], {
        encoding: 'utf8',
    }).trim();
}

export function artisanJson(args) {
    const out = artisan(args);

    return JSON.parse(out.slice(out.indexOf('{')));
}

export function log(message) {
    process.stdout.write(`  ${message}\n`);
}

/** Seed the shared account, in English whatever the reader's language. */
export function seedAccount() {
    log(`seeding ${EMAIL}`);
    artisan(['demo:reset', `--email=${EMAIL}`, `--password=${PASSWORD}`]);
    artisan([
        'tinker',
        '--execute',
        `App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()->update(['locale' => 'en']);`,
    ]);
}

export async function login(page, baseUrl) {
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: 'Email address' }).fill(EMAIL);
    await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 20000,
    });
}

export async function launchBrowser() {
    try {
        return await chromium.launch({ headless: HEADLESS });
    } catch {
        // The bundled headless shell is a slow download on a fresh machine;
        // the system Chrome is already there.
        return await chromium.launch({ headless: HEADLESS, channel: 'chrome' });
    }
}

/**
 * The cookie the app reads its theme from before it consults the operating
 * system, so a recording's theme leaves nothing to chance.
 */
export function themeCookie(theme) {
    return {
        cookies: [
            {
                name: 'appearance',
                value: theme,
                domain: '127.0.0.1',
                path: '/',
                expires: -1,
                httpOnly: false,
                secure: false,
                sameSite: 'Lax',
            },
        ],
        origins: [],
    };
}
