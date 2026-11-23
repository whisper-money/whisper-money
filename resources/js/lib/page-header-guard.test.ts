/**
 * App pages title themselves through PageHeader (docs/design-system.md, #1362).
 *
 * Before `components/page-header.tsx` each page wrote its own title block: an
 * `<h1>` in four sizes on some pages, a `HeadingSmall` (an `<h3>`) on others,
 * so most pages had no `<h1>` at all. This test fails when a page under
 * `pages/` writes a raw `<h1>` again. An `<h1>` handed to
 * `<EmptyStateTitle asChild>` is fine: that is a page whose only content is
 * its empty state.
 *
 * It reads markup, so it cannot tell a page that has no title at all:
 * docs/design-system.md and .ai/rules/design-system.md carry the rule itself.
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
    ...MARKETING_PAGES,
    // Auth and onboarding have their own layouts, which own the title.
    'pages/auth/',
    'pages/onboarding/',
    // The styleguide itself: a standalone page with its own sticky header,
    // outside the app shell.
    'pages/design-system.tsx',
    // Full-screen flow outside the app shell; its "All done" screen is a
    // celebration, not a page title.
    'pages/transactions/categorize.tsx',
    // An editorial report: the h1 is the month's generated headline, set
    // as a large bold sentence under a kicker, not the page's name.
    'pages/monthly-summaries/show.tsx',
];

const FORBIDDEN: readonly ForbiddenPattern[] = [
    {
        pattern: /(?<!<EmptyStateTitle\b[^>]*\basChild\b[^>]*>\s*)<h1\b/,
        what: 'writes its own <h1> instead of rendering <PageHeader>',
    },
];

describe('page header guard', () => {
    it('titles app pages through PageHeader', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED, 'pages'),
            'Use <PageHeader title description actions> from components/page-header.tsx for the title of an app page',
        ).toEqual([]);
    });

    it('recognises a hand-written page title', () => {
        const [{ pattern }] = FORBIDDEN;
        const handWritten = [
            `<h1 className="text-xl font-semibold tracking-tight">{title}</h1>`,
            `<div className="flex flex-col gap-1">\n<h1>{title}</h1>\n</div>`,
        ];
        const fine = [
            `<PageHeader title={title} />`,
            `<EmptyStateTitle asChild className="text-2xl font-semibold">\n<h1>{title}</h1>\n</EmptyStateTitle>`,
            `<h2 className="text-lg font-semibold">{title}</h2>`,
        ];

        expect(handWritten.filter((source) => !pattern.test(source))).toEqual(
            [],
        );
        expect(fine.filter((source) => pattern.test(source))).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
