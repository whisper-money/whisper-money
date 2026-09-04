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
     * The shapes a reader is offered: the 4:5 a feed wants and the 9:16 a story
     * wants. Both share screens draw from this, so neither can drift into
     * offering something the other does not.
     *
     * Wide is not among them. It was only ever a third download button on the
     * summary screen — nothing unfurls it, because the email and the public
     * page's og:image both use {@see default()} — so no route serves it and
     * nothing renders it any more. The case and the 16:9 branch in
     * `cards.monthly-summary` are dead weight kept for one commit; strip them
     * once nobody misses the shape.
     *
     * @return list<self>
     */
    public static function shareable(): array
    {
        return [self::Feed, self::Story];
    }
}
