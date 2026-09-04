import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { METALS } from './medal';

/*
 * The medal is drawn twice: here for the screen, and in Blade for the shareable
 * card, which cannot import a TypeScript constant. Two copies of the same
 * thirty-odd colours is the price of the language boundary — this is the thing
 * that makes them fail loudly instead of drifting apart, so a card and the
 * medal beside it on the page stay the same object.
 *
 * If this fails, one side was edited and the other was not. Fix the one that is
 * wrong; do not relax the test.
 */

const PHP = 'app/Enums/AchievementRarity.php';

const TIERS = {
    common: 'Common',
    uncommon: 'Uncommon',
    rare: 'Rare',
    epic: 'Epic',
} as const;

/** The `metal()` arms, read out of the enum as text. */
function phpMetals(): Record<string, Record<string, string>> {
    const source = readFileSync(PHP, 'utf8');
    const from = source.indexOf('public function metal(): array');
    const to = source.indexOf('public function medalShape(): array');

    expect(from, `${PHP} has no metal() to read`).toBeGreaterThan(-1);
    expect(to).toBeGreaterThan(from);

    const block = source.slice(from, to);
    const arms: Record<string, Record<string, string>> = {};

    for (const [, tier, body] of block.matchAll(
        /self::(Common|Uncommon|Rare|Epic) => \[(.*?)\n {12}\]/gs,
    )) {
        arms[tier] = Object.fromEntries(
            [...body.matchAll(/'(\w+)' => '([^']+)'/g)].map(
                ([, key, value]) => [key, value],
            ),
        );
    }

    return arms;
}

describe('the medal palette', () => {
    const arms = phpMetals();

    it('reads all four tiers off the enum', () => {
        expect(Object.keys(arms).sort()).toEqual([
            'Common',
            'Epic',
            'Rare',
            'Uncommon',
        ]);
    });

    it.each(Object.entries(TIERS))(
        'strikes %s in the same metal on the card as on the screen',
        (tier, phpCase) => {
            const screen = METALS[tier as keyof typeof TIERS];
            const card = arms[phpCase];

            for (const stop of [
                'hi',
                'light',
                'core',
                'shadow',
                'bounce',
                'rim',
                'ink',
            ] as const) {
                expect(card[stop], `${phpCase}.${stop}`).toBe(screen[stop]);
            }
        },
    );

    it('gives obsidian a crown of another metal on both sides', () => {
        expect(METALS.epic.bezel).toBe(METALS.rare);
        // The enum says the same thing by naming the tier it borrows.
        expect(arms.Epic.bezel).toBeUndefined();
        expect(
            readFileSync(PHP, 'utf8').includes("'bezel' => self::Rare"),
        ).toBe(true);
    });
});
