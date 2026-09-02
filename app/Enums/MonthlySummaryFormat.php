<?php

namespace App\Enums;

/**
 * The three shapes every card is rendered in. Pixel sizes are the ones the
 * networks expect, and the card template lays itself out from them.
 */
enum MonthlySummaryFormat: string
{
    case Feed = 'feed';
    case Story = 'story';
    case Wide = 'wide';

    /** @return array{int, int} */
    public function dimensions(): array
    {
        return match ($this) {
            self::Feed => [1080, 1350],
            self::Story => [1080, 1920],
            self::Wide => [1200, 675],
        };
    }

    /**
     * The format that rides inside the email and backs the public page's
     * og:image.
     */
    public static function default(): self
    {
        return self::Feed;
    }
}
