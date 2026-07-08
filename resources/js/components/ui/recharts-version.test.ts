import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * recharts < 3.9.0 ships a render-loop bug in its internal useElementOffset
 * hook: the measuring callback ref depended on the last bounding box, so every
 * setState re-invoked it, and it read viewport-relative getBoundingClientRect
 * with a 1px threshold. On mobile a mid-render viewport shift (the iOS URL bar
 * on scroll) moved the box > 1px each commit, looping until React aborted with
 * "Maximum update depth exceeded" and white-screened the dashboard
 * (Sentry PHP-LARAVEL-47). 3.9.0 rewrote the hook to measure off a
 * ResizeObserver with a stable ref, breaking the loop.
 *
 * The fix is "depend on a recharts that carries the upstream patch", so this is
 * the honest thing to test: a jsdom render test can't reproduce the loop
 * (zero-size rects, no ResizeObserver) and would pass on the buggy version too.
 * This guards against a lockfile/manifest change silently dropping below 3.9.0.
 */
describe('recharts version', () => {
    it('is >= 3.9.0 so the useElementOffset render loop stays fixed (PHP-LARAVEL-47)', () => {
        const { version } = JSON.parse(
            readFileSync(
                resolve(process.cwd(), 'node_modules/recharts/package.json'),
                'utf8',
            ),
        ) as { version: string };

        const [major, minor] = version.split('.').map(Number);
        const isAtLeast390 = major > 3 || (major === 3 && minor >= 9);

        expect(
            isAtLeast390,
            `recharts must be >= 3.9.0 to avoid the PHP-LARAVEL-47 render loop; found ${version}`,
        ).toBe(true);
    });
});
