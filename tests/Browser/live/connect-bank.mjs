// Live Enable Banking end-to-end check.
//
// Drives the REAL Enable Banking sandbox (Banco de Sabadell + BBVA) through the
// running dev server for the three flows we must never regress:
//   1. Connect a bank from settings (+ account mapping + sync).
//   2. Connect a bank during onboarding.
//   3. Reconnect an expired connection.
//
// Why this is a standalone script and not a Pest browser test: Enable Banking only
// redirects to the fixed registered URI (https://whisper.money.local/open-banking/
// callback). A RefreshDatabase Pest test runs an ephemeral server on a random port
// with a transactional DB the external redirect can never reach. So we drive the
// persistent dev server, let the bank redirect to the registered host (which is not
// served locally), capture the `code`+`state` it carries, and replay the callback
// against the dev server (the callback is stateless — keyed by state_token).
//
// Prerequisites:
//   - `composer run dev` running (APP at http://127.0.0.1:<port>, default 8921).
//   - Valid ENABLEBANKING_* config + key (the same config the app uses).
//
// Usage:
//   APP_BASE_URL=http://127.0.0.1:8921 node tests/Browser/live/connect-bank.mjs
//   HEADLESS=0 node tests/Browser/live/connect-bank.mjs   # watch it run
//
// See tests/Browser/live/README.md for details.

import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import process from 'node:process';
import { chromium } from 'playwright';

const VIDEO_DIR = 'tests/Browser/live/videos';

// `composer run dev` serves on a random port, so auto-detect the running
// `artisan serve --port=N` unless APP_BASE_URL is given explicitly.
function detectBaseUrl() {
    if (process.env.APP_BASE_URL) {
        return process.env.APP_BASE_URL.replace(/\/$/, '');
    }
    try {
        const port = execFileSync(
            'bash',
            [
                '-c',
                "ps ax -o command | grep -oE 'artisan serve --port=[0-9]+' | grep -oE '[0-9]+$' | head -1",
            ],
            { encoding: 'utf8' },
        ).trim();
        if (port) {
            return `http://127.0.0.1:${port}`;
        }
    } catch {
        // fall through to the default
    }
    return 'http://127.0.0.1:8921';
}

const BASE_URL = detectBaseUrl();
const HEADLESS = process.env.HEADLESS !== '0';
const OTP = '012345';
const SABADELL_USER = 'user1';
const SABADELL_PASS = '1234';
const BBVA_USER = 'user1';
const BBVA_PASS = '1234';

function artisan(args) {
    const out = execFileSync('php', ['artisan', ...args], { encoding: 'utf8' });
    return out.trim();
}

function artisanJson(args) {
    const out = artisan(args);
    const start = out.indexOf('{');
    return JSON.parse(out.slice(start));
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(`Assertion failed: ${message}`);
    }
}

function log(message) {
    process.stdout.write(`  ${message}\n`);
}

// Capture the bank's redirect to the registered (non-local) callback host and replay
// it against the dev server so the connection is finalized there.
function trackCallback(page) {
    const state = { url: null };
    page.on('request', (request) => {
        const url = request.url();
        if (
            url.includes('/open-banking/callback') &&
            url.includes('code=') &&
            !url.startsWith(BASE_URL)
        ) {
            state.url = url;
        }
    });
    return state;
}

async function replayCallback(page, captured) {
    assert(
        captured.url,
        'expected the bank to redirect to the callback with a code',
    );
    const replay = captured.url.replace(/^https?:\/\/[^/]+/, BASE_URL);
    captured.url = null;
    await page.goto(replay, { waitUntil: 'domcontentloaded' });
}

async function login(page, email, password) {
    // Each scenario starts from a clean session (the prior user is still logged in
    // otherwise, and /login would bounce straight to /dashboard).
    await page.context().clearCookies();
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: 'Email address' }).fill(email);
    await page.getByRole('textbox', { name: 'Password' }).fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 15000,
    });
}

const EB_CONTINUE = /Continue authentication|Continuar autenticación/;

async function driveSabadell(page) {
    await page.getByRole('button', { name: EB_CONTINUE }).click();
    await page.waitForTimeout(4000);
    await page.getByPlaceholder('Ej: 47587441A').fill(SABADELL_USER);
    await page.getByPlaceholder('Ej: 123456').first().fill(SABADELL_PASS);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await page.waitForTimeout(7000);
    // Consent + SCA: re-enter password, then the 6-digit OTP via the on-screen keypad.
    await page.getByPlaceholder('Ej: 123456').first().fill(SABADELL_PASS);
    await page.waitForTimeout(400);
    for (const digit of OTP.split('')) {
        await page.getByText(digit, { exact: true }).last().click();
        await page.waitForTimeout(150);
    }
    await page.waitForTimeout(400);
    await page.getByRole('button', { name: /Sign|Firmar/ }).click();
    await page.waitForTimeout(10000);
}

async function driveBbva(page) {
    await page.getByRole('button', { name: EB_CONTINUE }).click();
    await page.waitForTimeout(5000);
    const textboxes = await page.getByRole('textbox').all();
    await textboxes[0].fill(BBVA_USER);
    await textboxes[1].fill(BBVA_PASS);
    await page.getByRole('button', { name: 'Submit' }).first().click();
    await page.waitForTimeout(6000);
    await page.getByRole('textbox', { name: 'SMS Code' }).fill(OTP);
    await page.waitForTimeout(300);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForTimeout(10000);
}

async function selectBankInModal(page, bankName) {
    await page.locator('[role="dialog"] [role="combobox"]').click();
    await page.waitForTimeout(400);
    await page.getByRole('option', { name: 'Spain' }).click();
    await page.waitForTimeout(300);
    await page.locator('[role="dialog"] button:has-text("Continue")').click();
    await page.waitForTimeout(2800);
    await page
        .locator(`[role="dialog"] button:has-text("${bankName}")`)
        .first()
        .click();
    await page.waitForTimeout(300);
    await page.locator('[role="dialog"] button:has-text("Continue")').click();
    await page.waitForTimeout(800);
    await page.locator('[role="dialog"] button:has-text("Connect")').click();
    await page.waitForTimeout(6000);
}

async function scenarioSettingsConnect(page, settings) {
    log('Settings → connect Banco de Sabadell');
    const captured = trackCallback(page);
    await login(page, settings.email, settings.password);
    await page.goto(`${BASE_URL}/settings/connections`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: 'Connect Bank' }).click();
    await page.waitForTimeout(600);
    await selectBankInModal(page, 'Banco de Sabadell');
    await driveSabadell(page);
    await replayCallback(page, captured);

    // Onboarded user lands on account mapping; accept the defaults and sync.
    await page.waitForTimeout(1500);
    await page.getByRole('button', { name: 'Save & Sync' }).click();
    // Lands back on the connections list; wait for the connection to render.
    await page
        .getByText('Banco de Sabadell')
        .first()
        .waitFor({ timeout: 15000 });
    await page.waitForTimeout(2500);

    const mapped = artisanJson([
        'e2e:banking-fixture',
        'inspect',
        settings.email,
    ]).connection;
    assert(mapped, 'settings connection exists');
    assert(
        mapped.status === 'active',
        `settings status active (got ${mapped?.status})`,
    );
    assert(mapped.accounts_count > 0, 'settings accounts created');

    // The sync job runs on the default queue (the dev worker only drains "emails"),
    // so drive it synchronously to confirm transactions pull from the live sandbox.
    artisan(['banking:sync', '--connection=' + mapped.id, '--sync']);

    const { connection } = artisanJson([
        'e2e:banking-fixture',
        'inspect',
        settings.email,
    ]);
    assert(connection.transactions_count > 0, 'settings transactions synced');
    log(
        `✓ active · ${connection.accounts_count} accounts · ${connection.transactions_count} transactions`,
    );
}

async function scenarioOnboardingConnect(page, onboarding) {
    log('Onboarding → connect BBVA');
    const captured = trackCallback(page);
    await login(page, onboarding.email, onboarding.password);
    await page.goto(`${BASE_URL}/onboarding`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: "Let's Get Started" }).click();
    await page.waitForTimeout(800);
    await page
        .getByRole('button', { name: 'Create Your First Account' })
        .click();
    await page.waitForTimeout(800);
    await page.getByRole('button', { name: /Connected/ }).click();
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForTimeout(1000);
    // Inline (non-modal) connect on the onboarding step.
    await page.getByRole('combobox').last().click();
    await page.waitForTimeout(400);
    await page.getByRole('option', { name: 'Spain' }).click();
    await page.waitForTimeout(300);
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForTimeout(2800);
    await page.getByRole('button', { name: /^BBVA$/ }).click();
    await page.waitForTimeout(300);
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForTimeout(800);
    await page.getByRole('button', { name: 'Connect' }).click();
    await page.waitForTimeout(6000);
    await driveBbva(page);
    await replayCallback(page, captured);

    // The onboarding step hydrates its account list from the client store after
    // navigation; wait for it to render so we assert the UI (not just the DB) and the
    // recording ends on the real result rather than a mid-load frame.
    await page.getByText('Your Accounts').waitFor({ timeout: 15000 });
    await page.waitForTimeout(2500);

    const { connection } = artisanJson([
        'e2e:banking-fixture',
        'inspect',
        onboarding.email,
    ]);
    assert(connection, 'onboarding connection exists');
    assert(
        connection.status === 'active',
        `onboarding status active (got ${connection?.status})`,
    );
    assert(connection.accounts_count > 0, 'onboarding accounts auto-created');
    log(`✓ active · ${connection.accounts_count} accounts`);
}

async function scenarioReconnect(page, settings) {
    log('Settings → reconnect an expired connection');
    artisanJson(['e2e:banking-fixture', 'expire', settings.email]);

    const captured = trackCallback(page);
    await login(page, settings.email, settings.password);
    await page.goto(`${BASE_URL}/settings/connections`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: 'Reconnect' }).first().click();
    await page.waitForTimeout(6000);
    await driveSabadell(page);
    await replayCallback(page, captured);

    // Back on the connections list with the connection live again.
    await page
        .getByText('Banco de Sabadell')
        .first()
        .waitFor({ timeout: 15000 });
    await page.waitForTimeout(2500);

    const { connection } = artisanJson([
        'e2e:banking-fixture',
        'inspect',
        settings.email,
    ]);
    assert(connection, 'reconnect connection exists');
    assert(
        connection.status === 'active',
        `reconnect status active (got ${connection?.status})`,
    );
    assert(
        new Date(connection.valid_until) > new Date(),
        'reconnect refreshed valid_until',
    );
    assert(
        connection.accounts_without_external_id === 0,
        'reconnect refreshed all account ids',
    );
    log(
        `✓ active · valid until ${connection.valid_until} · ${connection.accounts_count} accounts retained`,
    );
}

async function scenarioSessionLost(page, settings) {
    log('Settings → connect, but the app session is lost on return');
    const captured = trackCallback(page);
    await login(page, settings.email, settings.password);
    await page.goto(`${BASE_URL}/settings/connections`, {
        waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: 'Connect Bank' }).click();
    await page.waitForTimeout(600);
    // Sabadell is already connected for this user from an earlier scenario; use BBVA.
    await selectBankInModal(page, 'BBVA');
    await driveBbva(page);

    // Simulate the app session being lost while the user was away at the bank (e.g. an
    // iOS PWA hands the redirect to Safari, where there is no session). The callback is
    // therefore unauthenticated on return.
    await page.context().clearCookies();
    await replayCallback(page, captured);

    // They must see the standalone "connected — go back to your app" confirmation
    // rather than being bounced to the login screen. This screen renders late in the
    // flow, so hold on it long enough to be clearly visible in the recording.
    await page.getByText('Bank account connected').waitFor({ timeout: 15000 });
    await page.getByText('go back to the app').waitFor({ timeout: 5000 });
    await page.waitForTimeout(6000);

    const { connection } = artisanJson([
        'e2e:banking-fixture',
        'inspect',
        settings.email,
    ]);
    assert(connection, 'session-lost connection exists');
    assert(
        connection.session_id === true,
        'connection was finalized despite the lost session',
    );
    log(
        `✓ confirmation screen shown · connection finalized (status ${connection.status})`,
    );
}

// Force English so the Enable Banking / bank sandbox pages render with the labels
// the selectors expect (they otherwise follow the browser locale).
const CONTEXT_OPTIONS = {
    ignoreHTTPSErrors: true,
    locale: 'en-US',
    extraHTTPHeaders: { 'Accept-Language': 'en-US,en;q=0.9' },
};

// Injected into every page so the recordings show where the cursor is and when it
// clicks (Playwright drives a real but invisible mouse). Runs on each document,
// including the external bank pages.
function installMouseHelper() {
    if (window.__mouseHelperInstalled) {
        return;
    }
    window.__mouseHelperInstalled = true;
    const attach = () => {
        const dot = document.createElement('div');
        dot.style.cssText =
            'pointer-events:none;position:fixed;top:0;left:0;z-index:2147483647;' +
            'width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;' +
            'background:rgba(220,38,38,.35);border:2px solid rgba(220,38,38,.9);' +
            'transition:transform .08s ease,background .15s ease;';
        document.body.appendChild(dot);
        document.addEventListener(
            'mousemove',
            (e) => {
                dot.style.left = e.clientX + 'px';
                dot.style.top = e.clientY + 'px';
            },
            true,
        );
        document.addEventListener(
            'mousedown',
            () => {
                dot.style.transform = 'scale(.6)';
                dot.style.background = 'rgba(220,38,38,.7)';
            },
            true,
        );
        document.addEventListener(
            'mouseup',
            () => {
                dot.style.transform = 'scale(1)';
                dot.style.background = 'rgba(220,38,38,.35)';
            },
            true,
        );
    };
    if (document.body) {
        attach();
    } else {
        document.addEventListener('DOMContentLoaded', attach);
    }
}

// Convert the webm Playwright produces to mp4 (plays in QuickTime / Preview / most
// players). Falls back to the webm if ffmpeg isn't installed.
function toMp4(webmPath) {
    const mp4Path = webmPath.replace(/\.webm$/, '.mp4');
    try {
        execFileSync(
            'ffmpeg',
            [
                '-y',
                '-loglevel',
                'error',
                '-i',
                webmPath,
                '-c:v',
                'libx264',
                '-pix_fmt',
                'yuv420p',
                '-movflags',
                '+faststart',
                mp4Path,
            ],
            { stdio: 'ignore' },
        );
        rmSync(webmPath, { force: true });
        return mp4Path;
    } catch {
        return webmPath;
    }
}

async function main() {
    log(`Seeding fixtures (base url: ${BASE_URL})`);
    const fixtures = artisanJson(['e2e:banking-fixture', 'seed']);

    rmSync(VIDEO_DIR, { recursive: true, force: true });
    mkdirSync(VIDEO_DIR, { recursive: true });

    const browser = await chromium.launch({ headless: HEADLESS });

    const results = [];
    // Each scenario gets its own recording context, so it produces one video and
    // starts from a clean session.
    const run = async (name, fn) => {
        const context = await browser.newContext({
            ...CONTEXT_OPTIONS,
            recordVideo: { dir: VIDEO_DIR, size: { width: 1280, height: 800 } },
        });
        await context.addInitScript(installMouseHelper);
        const page = await context.newPage();
        const video = page.video();
        let ok = true;
        let error = null;

        try {
            await fn(page);
            // A guaranteed clear still of the final screen, independent of video timing.
            await page
                .screenshot({ path: `${VIDEO_DIR}/${name}.png` })
                .catch(() => {});
        } catch (e) {
            ok = false;
            error = e.message;
            await page
                .screenshot({
                    path: `${VIDEO_DIR}/failure-${name}.png`,
                    fullPage: true,
                })
                .catch(() => {});
            log(`✗ ${e.message.split('\n')[0]} (at ${page.url()})`);
        } finally {
            await context.close();
        }

        const webmPath = `${VIDEO_DIR}/${name}.webm`;
        await video?.saveAs(webmPath).catch(() => {});
        await video?.delete().catch(() => {});
        results.push({ name, ok, error, video: toMp4(webmPath) });
    };

    try {
        await run('settings-connect', (page) =>
            scenarioSettingsConnect(page, fixtures.settings),
        );
        await run('onboarding-connect', (page) =>
            scenarioOnboardingConnect(page, fixtures.onboarding),
        );
        await run('reconnect-expired', (page) =>
            scenarioReconnect(page, fixtures.settings),
        );
        await run('session-lost-return', (page) =>
            scenarioSessionLost(page, fixtures.settings),
        );
    } finally {
        await browser.close();
    }

    process.stdout.write('\nLive Enable Banking e2e results:\n');
    for (const result of results) {
        process.stdout.write(
            `  ${result.ok ? 'PASS' : 'FAIL'}  ${result.name}  → ${result.video}${result.ok ? '' : ' — ' + result.error}\n`,
        );
    }

    process.exit(results.every((r) => r.ok) ? 0 : 1);
}

main().catch((error) => {
    process.stderr.write(`\nFatal: ${error.stack ?? error.message}\n`);
    process.exit(1);
});
