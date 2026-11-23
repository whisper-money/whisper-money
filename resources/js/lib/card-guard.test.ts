/**
 * A bordered group is a Card (docs/design-system.md, #1364).
 *
 * Before the migration, ~70 files drew their own card from a
 * `rounded-lg border p-4` div, each with its own radius and padding. This
 * test fails when such a class string comes back outside `components/ui/**`
 * and the allowlist: a string with a `rounded-lg`/`rounded-xl` radius, a plain
 * `border` and a padding.
 *
 * Bordered boxes that are not cards don't match, by what their classes say:
 * floating surfaces (tooltips, toasts, popovers) carry a `shadow-*`;
 * selectable options carry `cursor-pointer` or a `hover:` fill; tinted
 * notices and frames carry a `bg-muted`/`bg-destructive`/`bg-warning`/
 * `bg-background`/`bg-popover`/`bg-accent`/`bg-primary` fill; dropzones are
 * `border-dashed`; skeletons `animate-pulse`. Table and list wrappers have no
 * padding, so they don't match either.
 *
 * It reads class strings, so it cannot catch a card built another way (a
 * padding on a child, a `style`): docs/design-system.md and
 * .ai/rules/design-system.md carry the rule itself.
 */

import { describe, expect, it } from 'vitest';
import {
    findViolations,
    MARKETING_PAGES,
    missingPaths,
    type ForbiddenPattern,
} from './source-guard';

/**
 * Relative to resources/js. A trailing slash allows a whole folder. Each
 * entry is a bordered box with padding that is not a card.
 */
const ALLOWED: readonly string[] = [
    ...MARKETING_PAGES,
    // The primitives themselves.
    'components/ui/',
    // A specimen frame on /design-system, not app UI.
    'components/design-system/showcase.tsx',
    // A medal tile in a scrolling track, with locked and dashed states.
    'components/achievements/achievement-cell.tsx',
    // One row per request in a list.
    'components/integration-requests/integration-requests-board.tsx',
    // The picked file: a row with its name and a remove button.
    'components/import-step-upload.tsx',
    // A `<details>` disclosure, whose summary is the toggle.
    'components/full-import/step-file.tsx',
    // The fake widget drawn behind the lock: a skeleton, not content.
    'components/dashboard/custom-dashboards/locked-dashboard.tsx',
    // One row per dashboard and per widget error in a dialog list.
    'components/dashboard/custom-dashboards/manage-dashboards-dialog.tsx',
    'components/dashboard/custom-dashboards/user-widget-details-dialog.tsx',
];

const QUOTE = `["'\`]`;
const STRING_BODY = `[^"'\`]*`;
const NOT_A_CARD = String.raw`(?:shadow-|cursor-pointer|hover:|border-dashed|animate-pulse|\bbg-(?:muted|destructive|warning|background|popover|accent|primary)\b)`;

const FORBIDDEN: readonly ForbiddenPattern[] = [
    {
        pattern: new RegExp(
            QUOTE +
                `(?=${STRING_BODY}\\brounded-(?:lg|xl)(?=[\\s"'\`]))` +
                `(?=${STRING_BODY}(?<![\\w:-])border(?=[\\s"'\`]))` +
                `(?=${STRING_BODY}(?<![\\w:/-])p[xytblrse]?-[\\d.]+(?=[\\s"'\`]))` +
                `(?!${STRING_BODY}${NOT_A_CARD})` +
                STRING_BODY +
                QUOTE,
        ),
        what: 'hand-rolls a card (a rounded, bordered, padded box)',
    },
];

function matchesAny(source: string): boolean {
    return FORBIDDEN.some(({ pattern }) => pattern.test(source));
}

describe('card guard', () => {
    it('draws bordered groups with Card', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'Use <Card> from components/ui/card.tsx (size="sm" or "flush" where it fits)',
        ).toEqual([]);
    });

    it('recognises the hand-rolled shapes it replaced', () => {
        const handRolled = [
            `<div className="space-y-4 rounded-lg border border-border bg-card p-6">`,
            `<section className="rounded-lg border p-4">`,
            `<div className="flex flex-col gap-5 rounded-xl border p-5 sm:p-6">`,
            `<div className="flex flex-col gap-1 rounded-lg border px-3.5 py-3">`,
            `cn('flex flex-col gap-4 rounded-xl border bg-card p-4', className)`,
        ];
        const fine = [
            `<Card size="sm"><CardContent className="flex flex-col gap-3">`,
            `<div className="overflow-hidden rounded-lg border">`,
            `<ul className="divide-y rounded-lg border">`,
            `<div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">`,
            `<div className="flex w-full gap-3 rounded-lg border bg-popover p-3.5 shadow-lg">`,
            `className="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors"`,
            `<div className="flex items-center space-x-3 rounded-lg border p-4 hover:bg-accent">`,
            `<div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3">`,
            `<div className="flex flex-col gap-2 rounded-lg border-2 border-dashed p-6">`,
            `<div className="rounded-lg border-b p-4">`,
            `<div className="rounded-md border p-4">`,
        ];

        expect(handRolled.filter((source) => !matchesAny(source))).toEqual([]);
        expect(fine.filter(matchesAny)).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
