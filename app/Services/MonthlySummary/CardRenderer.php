<?php

namespace App\Services\MonthlySummary;

use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Enums\MonthlySummaryCard;
use App\Models\MonthlySummary;
use App\Services\Cards\CardBrowser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Renders a shareable card to a PNG on the public disk.
 *
 * The card is a Blade view rendered to a temporary HTML file and photographed by
 * headless Chromium — the same browser the Pest suite already installs — because
 * the alternative was redrawing thirty designs in PHP with GD and watching them
 * drift from the design on the first change. The browser itself lives in
 * {@see CardBrowser}, which achievement medals share.
 *
 * Every card the month can draw is rendered just before the email goes out, in
 * one browser, so the download buttons in the report are instant and nothing has
 * to start Chromium inside a web request. Anything the batch missed is still
 * rendered on demand the first time somebody asks for it.
 */
class CardRenderer
{
    public function __construct(
        private CardPresenter $presenter,
        private CardBrowser $browser,
    ) {}

    /**
     * The path on the public disk, rendering it first if it is not there yet.
     */
    public function path(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme, bool $pro): string
    {
        $path = $this->pathFor($summary, $card, $format, $theme, $pro);

        if (! Storage::disk('public')->exists($path)) {
            $this->render($summary, $card, $format, $theme, $pro, $path);
        }

        return $path;
    }

    /**
     * The absolute URL the email and the public page reference.
     */
    public function url(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme, bool $pro): string
    {
        return Storage::disk('public')->url($this->path($summary, $card, $format, $theme, $pro));
    }

    /**
     * Throw the cached images away, so a redesign or a corrected figure does not
     * leave a stale picture behind.
     */
    public function forget(MonthlySummary $summary): void
    {
        Storage::disk('public')->deleteDirectory($this->directoryFor($summary->id));
    }

    /**
     * Render everything this month can show, in one browser, before anybody asks
     * for it. Best-effort by design: this is a warm cache, and a card that does
     * not come out here is still rendered the first time it is downloaded.
     *
     * @param  list<MonthlySummaryCard>  $cards
     */
    public function warm(MonthlySummary $summary, array $cards, bool $pro): void
    {
        $jobs = [];

        foreach ($cards as $card) {
            foreach (CardFormat::shareable() as $format) {
                foreach (CardTheme::cases() as $theme) {
                    $path = $this->pathFor($summary, $card, $format, $theme, $pro);

                    if (Storage::disk('public')->exists($path)) {
                        continue;
                    }

                    $jobs[] = $this->job($summary, $card, $format, $theme, $pro, $path);
                }
            }
        }

        try {
            $this->renderJobs($jobs);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Throw away the cards of the months before this one, for this reader and
     * this space. A reader who browses back to an old report gets them drawn
     * again on the spot.
     */
    public function forgetBefore(MonthlySummary $summary): void
    {
        MonthlySummary::query()
            ->where('user_id', $summary->user_id)
            ->where('space_id', $summary->space_id)
            ->where('period', '<', $summary->period)
            ->pluck('id')
            ->each(fn (string $id) => Storage::disk('public')->deleteDirectory($this->directoryFor($id)));
    }

    /**
     * Everything that changes what comes out of the browser is part of the cache
     * key. The Pro badge, so a user who upgrades gets a new file rather than
     * yesterday's unbadged one. The theme, so the light and dark cuts of the same
     * card do not overwrite each other. And the language — read from the app
     * rather than passed in, exactly like {@see CardPresenter} reads it, so the
     * key and the copy inside the picture cannot disagree.
     */
    private function pathFor(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme, bool $pro): string
    {
        $suffix = $pro ? '-pro' : '';
        $locale = app()->getLocale();

        return $this->directoryFor($summary->id)."/{$card->value}-{$format->value}-{$theme->value}-{$locale}{$suffix}.png";
    }

    private function directoryFor(string $summaryId): string
    {
        return "monthly-summaries/{$summaryId}";
    }

    private function render(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme, bool $pro, string $path): void
    {
        $this->renderJobs([$this->job($summary, $card, $format, $theme, $pro, $path)]);
    }

    /**
     * One card written out as HTML, ready to be photographed.
     *
     * @return array{disk: string, path: string, html: string, png: string, width: int, height: int}
     */
    private function job(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme, bool $pro, string $path): array
    {
        [$width, $height] = $format->dimensions();

        return $this->browser->page(
            'public',
            $path,
            View::make('cards.monthly-summary', $this->presenter->viewData($summary, $card, $format, $theme, $pro))->render(),
            $width,
            $height,
        );
    }

    /**
     * @param  list<array{disk: string, path: string, html: string, png: string, width: int, height: int}>  $jobs
     */
    private function renderJobs(array $jobs): void
    {
        $this->browser->shoot($jobs);
    }
}
