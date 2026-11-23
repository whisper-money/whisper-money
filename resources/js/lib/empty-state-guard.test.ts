/**
 * Empty states render through EmptyState (docs/design-system.md, #1361).
 *
 * Before `components/ui/empty-state.tsx` there were ~40 hand-rolled "no X
 * yet" blocks, each with its own padding, border and type size. They shared
 * one shape: an element centred with `text-center` / `justify-center` (or
 * framed with `border-dashed`) whose first child is the empty copy, a
 * `__('No …')`, `__('Nothing …')`, `__('You have no …')`, or an
 * `emptyMessage` / `emptyText` prop. This test fails when that shape comes
 * back outside the allowlist.
 *
 * It reads markup, so it cannot catch every way to write an empty state:
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
const ALLOWED: readonly string[] = [...MARKETING_PAGES];

const CENTRED = String.raw`\b(?:text-center|justify-center|border-dashed)\b`;
const EMPTY_COPY = String.raw`\{\s*(?:__\(\s*['"\x60](?:No |Nothing|You have no)|empty(?:Message|Text)\b)`;

const FORBIDDEN: readonly ForbiddenPattern[] = [
    {
        pattern: new RegExp(
            String.raw`className=(?:"[^"]*${CENTRED}[^"]*"|\{cn\([^)]*${CENTRED}[^)]*\)\})[^>]*>\s*${EMPTY_COPY}`,
        ),
        what: 'hand-rolls an empty state (a centred or dashed block around "No …" copy)',
    },
];

describe('empty state guard', () => {
    it('renders empty states through EmptyState', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'Use <EmptyState> from components/ui/empty-state.tsx for a list, table, chart or page with nothing to show',
        ).toEqual([]);
    });

    it('recognises the hand-rolled shapes it replaced', () => {
        const [{ pattern }] = FORBIDDEN;
        const handRolled = [
            `<p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">\n{__('Nothing here yet.')}\n</p>`,
            `<div className="flex h-[300px] items-center justify-center text-muted-foreground">{__('No account data available')}</div>`,
            `<TableCell colSpan={3} className="h-24 text-center align-middle">{emptyMessage}</TableCell>`,
            `<div className={cn('p-6 text-center text-sm', framed && 'border-b')}>{emptyMessage}</div>`,
        ];
        const fine = [
            `<EmptyState size="inline"><EmptyStateDescription>{__('No labels found.')}</EmptyStateDescription></EmptyState>`,
            `<span className="text-2xl font-semibold">{__('Nothing to pay')}</span>`,
            `<p className="py-12 text-center text-sm text-muted-foreground">{error}</p>`,
        ];

        expect(handRolled.filter((source) => !pattern.test(source))).toEqual(
            [],
        );
        expect(fine.filter((source) => pattern.test(source))).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
