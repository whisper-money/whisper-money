<?php

namespace App\Services\MonthlySummary;

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Throwable;

/**
 * Renders a shareable card to a PNG on the public disk.
 *
 * The card is a Blade view rendered to a temporary HTML file and photographed by
 * headless Chromium — the same browser the Pest suite already installs — because
 * the alternative was redrawing fifteen designs in PHP with GD and watching them
 * drift from the design on the first change.
 *
 * Every card the month can draw is rendered just before the email goes out, in
 * one browser, so the download buttons in the report are instant and nothing has
 * to start Chromium inside a web request. Anything the batch missed is still
 * rendered on demand the first time somebody asks for it.
 */
class CardRenderer
{
    /**
     * Seconds a single render may take before it is abandoned. Generous: a cold
     * Chromium start plus a webfont fetch is not instant, and the caller decides
     * what to do without an image.
     */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Added to the timeout for each card in the batch. Screenshots after the
     * first are cheap — the browser is already up — but fifteen of them are not
     * free either.
     */
    private const TIMEOUT_PER_CARD_SECONDS = 10;

    public function __construct(private CardPresenter $presenter) {}

    /**
     * The path on the public disk, rendering it first if it is not there yet.
     */
    public function path(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro): string
    {
        $path = $this->pathFor($summary, $card, $format, $pro);

        if (! Storage::disk('public')->exists($path)) {
            $this->render($summary, $card, $format, $pro, $path);
        }

        return $path;
    }

    /**
     * The absolute URL the email and the public page reference.
     */
    public function url(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro): string
    {
        return Storage::disk('public')->url($this->path($summary, $card, $format, $pro));
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
            foreach (MonthlySummaryFormat::cases() as $format) {
                $path = $this->pathFor($summary, $card, $format, $pro);

                if (Storage::disk('public')->exists($path)) {
                    continue;
                }

                $jobs[] = $this->job($summary, $card, $format, $pro, $path);
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
     * The Pro badge is part of the picture, so it is part of the cache key: a
     * user who upgrades gets a new file rather than yesterday's unbadged one.
     */
    private function pathFor(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro): string
    {
        $suffix = $pro ? '-pro' : '';

        return $this->directoryFor($summary->id)."/{$card->value}-{$format->value}{$suffix}.png";
    }

    private function directoryFor(string $summaryId): string
    {
        return "monthly-summaries/{$summaryId}";
    }

    private function render(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro, string $path): void
    {
        $this->renderJobs([$this->job($summary, $card, $format, $pro, $path)]);
    }

    /**
     * One card written out as HTML, ready to be photographed.
     *
     * @return array{path: string, html: string, png: string, width: int, height: int}
     */
    private function job(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro, string $path): array
    {
        [$width, $height] = $format->dimensions();

        $htmlFile = tempnam(sys_get_temp_dir(), 'wm-card-').'.html';
        file_put_contents($htmlFile, View::make('cards.monthly-summary', $this->presenter->viewData($summary, $card, $format, $pro))->render());

        return [
            'path' => $path,
            'html' => $htmlFile,
            'png' => $htmlFile.'.png',
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Photograph a batch of cards in one browser and store whatever came out.
     *
     * A run that dies halfway still keeps the cards it managed to draw: the
     * missing ones are what the exception is about, and they cost one more
     * render rather than fifteen.
     *
     * HOME is stated rather than inherited. Chromium puts its crash database
     * under $HOME, and without a writable one the crash handler exits before
     * the browser is usable — `chrome_crashpad_handler: --database is required`,
     * then SIGTRAP. php-fpm does not hand its workers the environment the image
     * sets, so setting it in the container only ever fixed the queue worker:
     * every card drawn inside a web request still died. Set here, it holds
     * whichever process is doing the drawing.
     *
     * @param  list<array{path: string, html: string, png: string, width: int, height: int}>  $jobs
     */
    private function renderJobs(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        $manifest = tempnam(sys_get_temp_dir(), 'wm-cards-').'.json';
        file_put_contents($manifest, json_encode($jobs));

        try {
            $result = Process::env(['HOME' => sys_get_temp_dir()])
                ->timeout(self::TIMEOUT_SECONDS + self::TIMEOUT_PER_CARD_SECONDS * count($jobs))
                ->run([
                    (string) config('monthly_summary.node_binary'),
                    base_path('scripts/render-card.mjs'),
                    $manifest,
                ]);

            $missing = 0;

            foreach ($jobs as $job) {
                if (! is_file($job['png'])) {
                    $missing++;

                    continue;
                }

                Storage::disk('public')->put($job['path'], (string) file_get_contents($job['png']));
            }

            if ($missing > 0) {
                throw new RuntimeException("Card render failed for {$missing} of ".count($jobs).' card(s): '.trim($result->errorOutput() ?: $result->output()));
            }
        } finally {
            @unlink($manifest);

            foreach ($jobs as $job) {
                @unlink($job['html']);
                @unlink($job['png']);
            }
        }
    }
}
