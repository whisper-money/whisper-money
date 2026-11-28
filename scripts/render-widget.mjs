// Draws one dashboard widget in headless Chromium and says what came out, for
// the render_widget MCP tool (App\Services\Widgets\WidgetRenderer).
//
// The page is the widget preview MCP App's own HTML (the same SDK, module
// registry, error boundary and stylesheet as the dashboard), opened with the
// payload already on `window`, so it draws the bare cell at the size the
// dashboard's grid gives it (resources/js/widgets/preview/render-mode.tsx).
// The agent's TSX only ever runs inside this browser.
//
// Nothing leaves the machine: the payload carries the user's data. The browser
// resolves no host name, opens no WebSocket and no non-proxied WebRTC socket,
// runs no service worker, and every request that is not for the page's own
// file is refused. (The widget validator already refuses network code; this
// holds even if something slipped past it.)
//
// Usage:
//   node scripts/render-widget.mjs <job.json>
//
// Job: { "html": "preview.html", "payload": {…}, "theme": "light",
//        "cell": { "width": 395, "height": 256 }, "max_html_length": 30000,
//        "timeout_ms": 20000 }
//
// Prints one JSON object: what window.__whisperMoneyInspect() returns, plus
// `console` (the errors and warnings the page logged) and `timed_out`.

import { readFile } from 'node:fs/promises';
import process from 'node:process';
import { launchChromium } from './lib/chromium.mjs';

const [jobPath] = process.argv.slice(2);

if (!jobPath) {
    console.error('usage: render-widget.mjs <job.json>');
    process.exit(2);
}

const job = JSON.parse(await readFile(jobPath, 'utf8'));

/** An `a` cell is as tall as its content: give it room to grow into. */
const AUTO_VIEWPORT_HEIGHT = 2000;

/** Charts that ignore reduced motion still animate: wait for the DOM to rest. */
const QUIET_MS = 300;
const MAX_SETTLE_MS = 3000;

const MAX_CONSOLE_MESSAGES = 10;

const consoleMessages = [];

function logged(type, text) {
    if (consoleMessages.length < MAX_CONSOLE_MESSAGES) {
        consoleMessages.push({ type, text: String(text).slice(0, 500) });
    }
}

/** Past the widget's own timeout, time to close the browser and answer. */
const DEADLINE_MARGIN_MS = 5000;

function answer(result) {
    process.stdout.write(
        JSON.stringify({ ...result, console: consoleMessages }),
    );
}

const browser = await launchChromium([
    '--host-resolver-rules=MAP * ~NOTFOUND',
    '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
]);

// A widget stuck in a loop blocks the page, and page.evaluate() has no
// timeout of its own: past the deadline, answer with what is known. Exits
// once stdout is flushed, or PHP would read half an answer.
const deadline = setTimeout(() => {
    process.stdout.write(
        JSON.stringify({ timed_out: true, console: consoleMessages }),
        () => process.exit(0),
    );
    browser.close().catch(() => undefined);
}, job.timeout_ms + DEADLINE_MARGIN_MS);

try {
    const context = await browser.newContext({
        viewport: {
            width: job.cell.width,
            height: job.cell.height ?? AUTO_VIEWPORT_HEIGHT,
        },
        deviceScaleFactor: 1,
        colorScheme: job.theme,
        // Recharts skips its animations then, so the chart is drawn at once.
        reducedMotion: 'reduce',
        serviceWorkers: 'block',
    });

    await context.route(
        (url) => url.protocol !== 'file:',
        (route) => route.abort(),
    );
    await context.routeWebSocket(/.*/, (socket) => socket.close());

    const page = await context.newPage();

    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            logged(message.type(), message.text());
        }
    });
    page.on('pageerror', (error) => logged('error', error.message));

    await page.addInitScript(
        (request) => {
            window.__whisperMoneyRender = request;
        },
        { payload: job.payload, theme: job.theme, cell: job.cell },
    );

    await page.goto(`file://${job.html}`, { waitUntil: 'load' });
    await page.waitForFunction(
        () => {
            const status = document
                .querySelector('[data-render-status]')
                ?.getAttribute('data-render-status');

            return (
                status !== undefined && status !== null && status !== 'loading'
            );
        },
        null,
        { timeout: job.timeout_ms },
    );
    await page.evaluate(
        ([quietMs, maxMs]) =>
            new Promise((resolve) => {
                let quiet = setTimeout(done, quietMs);
                const cap = setTimeout(done, maxMs);
                const observer = new MutationObserver(() => {
                    clearTimeout(quiet);
                    quiet = setTimeout(done, quietMs);
                });

                function done() {
                    observer.disconnect();
                    clearTimeout(quiet);
                    clearTimeout(cap);
                    resolve();
                }

                observer.observe(document.body, {
                    subtree: true,
                    childList: true,
                    attributes: true,
                    characterData: true,
                });
            }),
        [QUIET_MS, MAX_SETTLE_MS],
    );

    const inspection = await page.evaluate(
        (maxLength) => window.__whisperMoneyInspect(maxLength),
        job.max_html_length,
    );

    answer({ ...inspection, timed_out: false });
} catch (error) {
    if (error?.name === 'TimeoutError') {
        answer({ timed_out: true });
    } else {
        throw error;
    }
} finally {
    clearTimeout(deadline);
    await browser.close();
}
