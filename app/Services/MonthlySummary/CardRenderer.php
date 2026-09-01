<?php

namespace App\Services\MonthlySummary;

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Renders a shareable card to a PNG on the public disk.
 *
 * The card is a Blade view rendered to a temporary HTML file and photographed by
 * headless Chromium — the same browser the Pest suite already installs — because
 * the alternative was redrawing fifteen designs in PHP with GD and watching them
 * drift from the design on the first change.
 *
 * Only the feed format is rendered up front, when the summary is built: it is
 * the one that rides inside the email and backs the public page's og:image. The
 * other two are rendered the first time somebody asks for them and then cached,
 * so nobody pays for twelve images a user will never open.
 */
class CardRenderer
{
    /**
     * Seconds a single render may take before it is abandoned. Generous: a cold
     * Chromium start plus a webfont fetch is not instant, and the caller decides
     * what to do without an image.
     */
    private const TIMEOUT_SECONDS = 60;

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
        Storage::disk('public')->deleteDirectory($this->directoryFor($summary));
    }

    /**
     * The Pro badge is part of the picture, so it is part of the cache key: a
     * user who upgrades gets a new file rather than yesterday's unbadged one.
     */
    private function pathFor(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro): string
    {
        $suffix = $pro ? '-pro' : '';

        return $this->directoryFor($summary)."/{$card->value}-{$format->value}{$suffix}.png";
    }

    private function directoryFor(MonthlySummary $summary): string
    {
        return "monthly-summaries/{$summary->id}";
    }

    private function render(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, bool $pro, string $path): void
    {
        [$width, $height] = $format->dimensions();

        $html = View::make('cards.monthly-summary', $this->presenter->viewData($summary, $card, $format, $pro))->render();

        $htmlFile = tempnam(sys_get_temp_dir(), 'wm-card-').'.html';
        $pngFile = $htmlFile.'.png';
        file_put_contents($htmlFile, $html);

        try {
            $this->screenshot($htmlFile, $pngFile, $width, $height);
            Storage::disk('public')->put($path, (string) file_get_contents($pngFile));
        } finally {
            @unlink($htmlFile);
            @unlink($pngFile);
        }
    }

    private function screenshot(string $htmlFile, string $pngFile, int $width, int $height): void
    {
        $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
            (string) config('monthly_summary.node_binary'),
            base_path('scripts/render-card.mjs'),
            $htmlFile,
            $pngFile,
            (string) $width,
            (string) $height,
        ]);

        if ($result->failed() || ! is_file($pngFile)) {
            throw new RuntimeException('Card render failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
