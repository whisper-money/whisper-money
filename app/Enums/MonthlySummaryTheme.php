<?php

namespace App\Enums;

/**
 * The two skins every card can be drawn in. Light is what the email carries and
 * what the public page unfurls; the report screen offers both, because a card
 * posted into a dark feed reads better dark.
 */
enum MonthlySummaryTheme: string
{
    case Light = 'light';
    case Dark = 'dark';

    public function isDark(): bool
    {
        return $this === self::Dark;
    }

    /**
     * The theme the email image and the public page's og:image are drawn in.
     */
    public static function default(): self
    {
        return self::Light;
    }
}
