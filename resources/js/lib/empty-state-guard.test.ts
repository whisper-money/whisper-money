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

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
