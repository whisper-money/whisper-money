<?php

namespace App\Services\Achievements;

use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Models\Achievement;
use App\Services\Cards\CardBrowser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Renders one medal to a shareable PNG on the public disk.
 *
 * Drawn on demand and cached, with no warm-up job behind it — unlike the
 * monthly summary, which renders every card it can before the email goes out.
 * The arithmetic is why: a reader with forty-six medals has 46 × 2 formats × 2
 * themes × 2 amount states of pictures nobody asked for, and they share one or
 * two. So the first request pays for the browser and every request after it is
 * a file read.
 *
 * That first request costing a cold Chromium is also why the share dialog paints
 * a preview before it offers the button: by the time somebody taps Share the
 * PNG is already drawn and in their browser cache, which is what keeps
 * `navigator.share()` inside the user gesture it is only allowed to run in.
 *
 * These land on the PRIVATE disk, unlike the monthly summary's cards. The
 * summary needs public URLs — an email embeds them, a shared page unfurls them
 * — and it can afford them because no absolute amount is ever drawn on one. A
 * medal card writes the reader's amount by default, so a file under `/storage`,
 * which `storage:link` serves unauthenticated, would be a hole in the account:
 * a UUID in the path is obscurity, not authorisation. Every way into these goes
 * through the controller instead.
 */
class CardRenderer
{
    /** Not `public`: see the note above. Nothing here needs a URL of its own. */
    public const DISK = 'local';

    /**
     * Bumped when the medal drawing or the card layout changes, so a redesign
     * cannot serve yesterday's picture: the old files simply stop being named.
     * They are left behind rather than swept — a stale PNG costs disk, and a
     * sweep running while somebody is mid-share costs them their card.
     */
    private const DESIGN = 'v1';

    public function __construct(
        private CardPresenter $presenter,
        private CardBrowser $browser,
    ) {}

    /**
     * The path on {@see DISK}, rendering it first if it is not there yet.
     */
    public function path(
        Achievement $achievement,
        Definition $definition,
        string $currency,
        CardFormat $format,
        CardTheme $theme,
        bool $amount,
    ): string {
        $path = $this->pathFor($achievement, $format, $theme, $amount);

        if (! Storage::disk(self::DISK)->exists($path)) {
            [$width, $height] = $format->dimensions();

            $this->browser->shoot([
                $this->browser->page(
                    self::DISK,
                    $path,
                    View::make('cards.achievement', $this->presenter->viewData(
                        $definition,
                        $achievement->achieved_on,
                        $currency,
                        $format,
                        $theme,
                        $amount,
                    ))->render(),
                    $width,
                    $height,
                ),
            ]);
        }

        return $path;
    }

    /**
     * Everything that changes what comes out of the browser is part of the key:
     * the format and the theme, so the cuts do not overwrite each other; the
     * language, read from the app exactly as {@see CardPresenter} reads it, so
     * the key and the copy inside the picture cannot disagree; and whether the
     * amount is on it, because that is the reader's choice and the two versions
     * are different pictures. {@see DESIGN} sits above all of it, which is what
     * a medal's own content never needing to be invalidated buys: a row is
     * written once and never revoked, so only the design can go stale.
     */
    private function pathFor(Achievement $achievement, CardFormat $format, CardTheme $theme, bool $amount): string
    {
        $locale = app()->getLocale();
        $suffix = $amount ? '' : '-plain';

        return $this->directoryFor($achievement->user_id)."/{$achievement->key}-{$format->value}-{$theme->value}-{$locale}{$suffix}.png";
    }

    private function directoryFor(string $userId): string
    {
        return 'achievements/'.self::DESIGN."/{$userId}";
    }
}
