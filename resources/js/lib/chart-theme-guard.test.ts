/**
 * No component branches on the chart theme (docs/design-system.md, #1359).
 *
 * What a chart role looks like in each chart theme is decided in CSS (the
 * chart role tokens next to the `[data-chart-color=…]` blocks in
 * resources/css/app.css). Code draws with those tokens and never reads the
 * theme, so this test fails when a file under resources/js reads
 * `chartColorScheme`, the `data-chart-color` attribute, or compares against
 * the colorful theme.
 *
 * Only the files that store or set the theme are allowed to.
 */

import { describe, expect, it } from 'vitest';
import {
    findViolations,
    missingPaths,
    type ForbiddenPattern,
} from './source-guard';

/** Relative to resources/js. A trailing slash allows a whole folder. */
const ALLOWED: readonly string[] = [
    // Persists the scheme and puts it on <html>.
    'hooks/use-chart-color-scheme.tsx',
    // The widget preview page puts the user's scheme on its own <html>.
    'widgets/preview/widget-preview.tsx',
    // The setting where the user picks the scheme.
    'pages/settings/appearance.tsx',
    // The /design-system page shows every theme side by side.
    'pages/design-system.tsx',
    'components/design-system/',
    // The shared prop's type.
    'types/index.d.ts',
];

const FORBIDDEN: readonly ForbiddenPattern[] = [
    { pattern: /\bchartColorScheme\b/, what: 'reads chartColorScheme' },
    { pattern: /\bisColorful\b/, what: 'uses an isColorful flag' },
    {
        pattern: /[!=]==?\s*['"`]colorful['"`]|['"`]colorful['"`]\s*[!=]==?/,
        what: "compares against 'colorful'",
    },
    {
        pattern: /data-chart-color|dataset\.chartColor/,
        what: 'reads the data-chart-color attribute',
    },
];

describe('chart theme guard', () => {
    it('keeps the chart theme decision in CSS', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'Use the chart role tokens (lib/chart-colors.ts) instead of reading the chart theme',
        ).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
