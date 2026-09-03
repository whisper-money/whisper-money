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
}
