// The one headless Chromium every server-side render starts: the summary and
// medal cards (render-card.mjs) and render_widget (render-widget.mjs).
//
// Playwright is used rather than a second browser stack because it is already a
// dependency of the Pest browser suite, so the pages are drawn by the same
// Chromium the tests run on.

import { chromium } from 'playwright';

const ARGS = ['--no-sandbox', '--disable-dev-shm-usage'];

/**
 * `channel: 'chromium'` asks for the full browser rather than the headless
 * shell, which is a separate download that `playwright install chromium` does
 * not always place. Where only the shell is installed (the Browser test suite
 * installs `--only-shell`), that one draws the page instead.
 *
 * @param {string[]} extraArgs
 */
export async function launchChromium(extraArgs = []) {
    const args = [...ARGS, ...extraArgs];

    try {
        return await chromium.launch({ channel: 'chromium', args });
    } catch (error) {
        // Only a missing binary: a browser that crashed is not retried.
        if (!String(error?.message).includes("Executable doesn't exist")) {
            throw error;
        }

        return await chromium.launch({ args });
    }
}
