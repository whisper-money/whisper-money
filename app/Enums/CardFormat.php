<?php

namespace App\Enums;

/**
 * The three shapes every card is rendered in. Pixel sizes are the ones the
 * networks expect, and the card template lays itself out from them.
 */
enum CardFormat: string
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

    /**
     * The shapes an achievement medal is offered in: the 4:5 a feed wants and
     * the 9:16 a story wants. Wide is a link-preview shape, and the medal card
     * is a centred column that has nothing to do in it, so it is not drawn.
     *
     * @return list<self>
     */
    public static function forAchievements(): array
    {
        return [self::Feed, self::Story];
    }
}
