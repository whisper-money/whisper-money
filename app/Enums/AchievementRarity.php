<?php

namespace App\Enums;

/**
 * The tier assigned to each medal by hand, shown everywhere the medal is. Not
 * derived from how many members hold it: that share is shown next to the tier
 * inside the app, once enough members have been evaluated for it to mean
 * anything, and never on a shared card.
 */
enum AchievementRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Epic = 'epic';

    public function label(): string
    {
        return match ($this) {
            self::Common => __('Common'),
            self::Uncommon => __('Uncommon'),
            self::Rare => __('Rare'),
            self::Epic => __('Epic'),
        };
    }

    /**
     * The metal this tier is struck in, as the five stops light crosses on a
     * struck disc plus the edge and the engraving.
     *
     * KEEP IN STEP WITH `resources/js/components/achievements/medal.tsx`, which
     * carries the same table for the screen: a shareable card and the medal
     * beside it have to be the same object. `medal-palette.test.ts` fails when
     * the two drift. Values are oklch and go into the card verbatim — the
     * Chromium that photographs it renders oklch natively.
     *
     * @return array{hi: string, light: string, core: string, shadow: string, bounce: string, rim: string, ink: string, sheen: float, bezel: ?self}
     */
    public function metal(): array
    {
        return match ($this) {
            self::Common => [
                'hi' => 'oklch(0.81 0.085 50)',
                'light' => 'oklch(0.71 0.12 43)',
                'core' => 'oklch(0.60 0.13 38)',
                'shadow' => 'oklch(0.44 0.10 34)',
                'bounce' => 'oklch(0.58 0.12 45)',
                'rim' => 'oklch(0.38 0.09 34)',
                'ink' => 'oklch(0.26 0.06 36)',
                'sheen' => 0.34,
                'bezel' => null,
            ],
            self::Uncommon => [
                'hi' => 'oklch(0.94 0.008 250)',
                'light' => 'oklch(0.85 0.011 252)',
                'core' => 'oklch(0.73 0.014 254)',
                'shadow' => 'oklch(0.55 0.017 257)',
                'bounce' => 'oklch(0.79 0.012 250)',
                // The dark struck edge is load-bearing: without it a face this
                // pale dissolves into white paper.
                'rim' => 'oklch(0.47 0.017 256)',
                'ink' => 'oklch(0.28 0.012 255)',
                'sheen' => 0.4,
                'bezel' => null,
            ],
            self::Rare => [
                'hi' => 'oklch(0.95 0.07 97)',
                'light' => 'oklch(0.88 0.13 92)',
                'core' => 'oklch(0.79 0.155 86)',
                'shadow' => 'oklch(0.60 0.135 72)',
                'bounce' => 'oklch(0.85 0.14 88)',
                'rim' => 'oklch(0.51 0.12 68)',
                'ink' => 'oklch(0.30 0.07 70)',
                'sheen' => 0.42,
                'bezel' => null,
            ],
            // Obsidian is the break in the ladder: a gold crown, and the only
            // medal whose pictogram inverts.
            self::Epic => [
                'hi' => 'oklch(0.42 0.020 262)',
                'light' => 'oklch(0.33 0.018 264)',
                'core' => 'oklch(0.255 0.015 266)',
                'shadow' => 'oklch(0.165 0.012 268)',
                'bounce' => 'oklch(0.34 0.018 262)',
                'rim' => 'oklch(0.12 0.010 268)',
                'ink' => 'oklch(0.87 0.12 92)',
                'sheen' => 0.14,
                'bezel' => self::Rare,
            ],
        };
    }

    /**
     * Radii on the medal's 48-unit grid, and the pictogram each tier can carry:
     * a crown eats into the face, so the glyph shrinks as the rim grows. Same
     * table as `medal.tsx`, same reason.
     *
     * @return array{face: float, glyph: float, crown: bool, ring: bool}
     */
    public function medalShape(): array
    {
        return match ($this) {
            self::Common => ['face' => 20, 'glyph' => 21, 'crown' => false, 'ring' => false],
            self::Uncommon => ['face' => 18.5, 'glyph' => 19, 'crown' => false, 'ring' => true],
            self::Rare => ['face' => 17.5, 'glyph' => 16.5, 'crown' => true, 'ring' => false],
            self::Epic => ['face' => 18, 'glyph' => 15, 'crown' => true, 'ring' => false],
        };
    }
}
