/**
 * Figures render through StatTile (docs/design-system.md, #1363).
 *
 * Before `components/ui/stat-tile.tsx`, each feature drew its own "label and
 * a number" block, with its own label, type size and spacing. The hand-rolled
 * ones shared two shapes, and this test fails when either comes back outside
 * the allowlist:
 *
 * - a small muted label (`text-xs text-muted-foreground`) followed straight
 *   away by an `AmountDisplay` or `WholeAmount`, the figure it names;
 * - a quiet tile (`bg-muted/50 … p-4`) that opens with that label.
 *
 * It reads markup, so it cannot catch every way to write a figure (a label
 * passed as a prop, a `<dt>` before a `{children}`): docs/design-system.md and
 * .ai/rules/design-system.md carry the rule itself.
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

const MUTED_LABEL = String.raw`<(?:p|span|dt|div)\s+className="[^"]*\btext-xs\b[^"]*\btext-muted-foreground\b[^"]*"\s*>`;
const LABEL_TEXT = String.raw`\s*(?:\{[^{}<>]*\}|[^<{}]+)\s*</(?:p|span|dt|div)>`;
const AMOUNT = String.raw`\s*(?:<(?:div|span)\b[^>]*>\s*)?<(?:AmountDisplay|WholeAmount)\b`;

const FORBIDDEN: readonly ForbiddenPattern[] = [
    {
        pattern: new RegExp(MUTED_LABEL + LABEL_TEXT + AMOUNT),
        what: 'hand-rolls a stat tile (a muted label straight before its amount)',
    },
    {
        pattern: new RegExp(
            String.raw`className="[^"]*\bbg-muted/50\b[^"]*\bp-4\b[^"]*"\s*>\s*(?:<div\b[^>]*>\s*)?` +
                MUTED_LABEL,
        ),
        what: 'hand-rolls a stat tile (a muted tile that opens with a label)',
    },
];

function matchesAny(source: string): boolean {
    return FORBIDDEN.some(({ pattern }) => pattern.test(source));
}

describe('stat tile guard', () => {
    it('renders figures through StatTile', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'Use <StatTile> from components/ui/stat-tile.tsx for a label and its figure',
        ).toEqual([]);
    });

    it('recognises the hand-rolled shapes it replaced', () => {
        // Built at runtime so the translation-key scanner doesn't read the
        // fixtures' copy as keys missing from lang/*.json.
        const t = '__';
        const handRolled = [
            `<p className="text-xs text-muted-foreground">\n{${t}('Income')}\n</p>\n<AmountDisplay amountInCents={income} currencyCode={code} weight="semibold" />`,
            `<p className="text-xs text-muted-foreground">{label}</p>\n<WholeAmount amountInCents={amount} currencyCode={currency} />`,
            `<dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Used</dt>\n<div className="py-1"><AmountDisplay amountInCents={used} currencyCode={code} />`,
            `<div className="rounded-lg bg-muted/50 p-4">\n<p className="text-xs text-muted-foreground">{label}</p>`,
            `<div className="rounded-lg bg-muted/50 p-4">\n<div className="flex h-6 items-center">\n<p className="text-xs text-muted-foreground">{${t}('Margin')}</p>`,
        ];
        const fine = [
            `<StatTile size="sm"><StatTileLabel>{${t}('Income')}</StatTileLabel><StatTileValue><AmountDisplay amountInCents={income} currencyCode={code} /></StatTileValue></StatTile>`,
            `<span className="text-xs text-muted-foreground">{${t}('Savings rate')}</span>\n<span className="text-sm font-medium">{rate}%</span>`,
            `<p className="text-xs text-muted-foreground">\n<WholeAmount amountInCents={reserved} currencyCode={currency} /> reserved\n</p>`,
            `<div className="rounded-md bg-muted/50 px-3 py-2"><p className="text-sm font-medium">Free</p>`,
        ];

        expect(handRolled.filter((source) => !matchesAny(source))).toEqual([]);
        expect(fine.filter(matchesAny)).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
