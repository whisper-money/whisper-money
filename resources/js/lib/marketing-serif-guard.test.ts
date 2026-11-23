/**
 * The serif is a marketing font, not part of the design system
 * (docs/design-system.md, Typography).
 *
 * IBM Plex Serif italic gives the landing hero its
 * editorial look, through `font-marketing-serif` in `app.css` (the
 * documentation diagrams set it in CSS, `.dg-aside`). The app speaks IBM Plex
 * Sans and Google Sans Code only. This test fails when a file outside the
 * marketing pages names the serif: its class, the class it had before
 * (`font-heading`, which read like "the heading font" and drifted into app
 * pages), the font family itself, or Tailwind's `font-serif`.
 */

import { describe, expect, it } from 'vitest';
import {
    findViolations,
    MARKETING_PAGES,
    missingPaths,
    type ForbiddenPattern,
} from './source-guard';

/** Relative to resources/js. A trailing slash allows a whole folder. */
const ALLOWED: readonly string[] = [
    // The landing hero's h1.
    'pages/welcome.tsx',
    // The documentation pages and their diagrams (`.dg-aside`).
    'pages/documentation/',
    'components/documentation/',
];

const FORBIDDEN: readonly ForbiddenPattern[] = [
    {
        pattern: /\bfont-marketing-serif\b/,
        what: 'uses font-marketing-serif, which is for marketing pages only',
    },
    {
        pattern: /\bfont-heading\b/,
        what: 'uses font-heading, a class that no longer exists (the serif is font-marketing-serif, marketing pages only)',
    },
    {
        pattern: /Plex Serif/i,
        what: 'names IBM Plex Serif, a marketing-only font',
    },
    {
        pattern: /\bfont-serif\b/,
        what: 'uses font-serif; the app has no serif',
    },
];

describe('marketing serif guard', () => {
    it('keeps the serif out of the app', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'The app uses font-sans (IBM Plex Sans) and font-mono (Google Sans Code) only',
        ).toEqual([]);
    });

    it('recognises every way to reach the serif', () => {
        const serif = [
            `<h1 className="font-marketing-serif text-4xl">`,
            `<p className="font-heading mt-1 text-xs">`,
            `style={{ fontFamily: "'IBM Plex Serif', serif" }}`,
            `<h2 className="font-serif italic">`,
        ];
        const fine = [
            `<h1 className="text-xl font-semibold tracking-tight">`,
            `<span className="font-mono text-xs">`,
            `const headingId = 'section-heading';`,
        ];
        const matches = (source: string) =>
            FORBIDDEN.some(({ pattern }) => pattern.test(source));

        expect(serif.filter((source) => !matches(source))).toEqual([]);
        expect(fine.filter(matches)).toEqual([]);
    });

    it('allows only marketing pages that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
        expect(
            ALLOWED.filter((entry) => !MARKETING_PAGES.includes(entry)),
        ).toEqual([]);
    });
});
