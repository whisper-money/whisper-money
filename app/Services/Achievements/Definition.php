<?php

namespace App\Services\Achievements;

use App\Enums\AchievementFigure;
use App\Enums\AchievementRarity;

/**
 * One medal as the catalog describes it: which track it sits on, its position
 * there, its tier, how its number reads and the pictogram the frontend draws.
 *
 * Money medals carry no threshold of their own: the ladder for the reader's
 * currency supplies it by position, so `net_worth.4` is the fourth net worth
 * medal for everyone and a different figure per currency.
 */
final readonly class Definition
{
    public function __construct(
        public string $key,
        public string $track,
        public int $tier,
        public AchievementRarity $rarity,
        public AchievementFigure $figure,
        public string $icon,
        public string $name,
        public int|float|null $threshold = null,
    ) {}

    /**
     * Money medals read their threshold from a ladder: `monthly`, `yearly` or
     * `net_worth`. Everything else is fixed in the catalog.
     */
    public function ladder(): ?string
    {
        return match ($this->track) {
            'monthly_saving', 'monthly_investing' => 'monthly',
            'yearly_saving' => 'yearly',
            'net_worth' => 'net_worth',
            default => null,
        };
    }
}
