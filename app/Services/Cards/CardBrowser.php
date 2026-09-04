<?php

namespace App\Services\Cards;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Photographs HTML at a size and stores the PNG where the caller asks.
 *
 * This is the half of the card pipeline that knows nothing about what it is
 * drawing — one headless Chromium, a batch of pages, a PNG each. Monthly
 * summaries and achievement medals both come through here, so the parts that
 * were painful to get right (the crashpad HOME, the batch timeout, keeping the
 * cards that rendered when one of them dies) are solved once.
 *
 * What a card *is* — its path, its disk, its cache key, its Blade view —
 * belongs to whatever owns the card, not here. The disk travels with each page
 * rather than being fixed: a summary card needs a public URL an email can
 * embed, and a medal card must not have one.
 */
class CardBrowser
{
    /**
     * Seconds a single render may take before it is abandoned. Generous: a cold
     * Chromium start plus a webfont fetch is not instant, and the caller decides
     * what to do without an image.
     */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Added to the timeout for each card in the batch. Screenshots after the
     * first are cheap — the browser is already up — but thirty of them are not
     * free either.
     */
    private const TIMEOUT_PER_CARD_SECONDS = 10;

    /**
     * Write one page out as HTML, ready to be photographed. The caller decides
     * which disk the PNG lands on and where; everything else is scratch space.
     *
     * @return array{disk: string, path: string, html: string, png: string, width: int, height: int}
     */
    public function page(string $disk, string $path, string $html, int $width, int $height): array
    {
        $htmlFile = tempnam(sys_get_temp_dir(), 'wm-card-').'.html';
        file_put_contents($htmlFile, $html);

        return [
            'disk' => $disk,
            'path' => $path,
            'html' => $htmlFile,
            'png' => $htmlFile.'.png',
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Photograph a batch of pages in one browser and store whatever came out.
     *
     * A run that dies halfway still keeps the cards it managed to draw: the
     * missing ones are what the exception is about, and they cost one more
     * render rather than thirty.
     *
     * @param  list<array{disk: string, path: string, html: string, png: string, width: int, height: int}>  $pages
     */
    public function shoot(array $pages): void
    {
        if ($pages === []) {
            return;
        }

        $manifest = tempnam(sys_get_temp_dir(), 'wm-cards-').'.json';
        file_put_contents($manifest, json_encode($pages));

        try {
            $result = Process::env($this->environment())
                ->timeout(self::TIMEOUT_SECONDS + self::TIMEOUT_PER_CARD_SECONDS * count($pages))
                ->run([
                    (string) config('monthly_summary.node_binary'),
                    base_path('scripts/render-card.mjs'),
                    $manifest,
                ]);

            $missing = 0;

            foreach ($pages as $page) {
                if (! is_file($page['png'])) {
                    $missing++;

                    continue;
                }

                Storage::disk($page['disk'])->put($page['path'], (string) file_get_contents($page['png']));
            }

            if ($missing > 0) {
                throw new RuntimeException("Card render failed for {$missing} of ".count($pages).' card(s): '.trim($result->errorOutput() ?: $result->output()));
            }
        } finally {
            @unlink($manifest);

            foreach ($pages as $page) {
                @unlink($page['html']);
                @unlink($page['png']);
            }
        }
    }

    /**
     * What the render subprocess is given on top of what it inherits.
     *
     * Chromium keeps its crash database under $HOME, and without a writable one
     * the crash handler exits before the browser is usable —
     * `chrome_crashpad_handler: --database is required`, then SIGTRAP. php-fpm
     * does not hand its workers the environment the image sets, so fixing it in
     * the container only ever fixed the queue worker: every card drawn inside a
     * web request still died.
     *
     * But HOME is also where Playwright looks for the browsers it installed, so
     * moving it unconditionally hides them from itself — which is why this never
     * worked on a developer's machine, where nothing sets
     * PLAYWRIGHT_BROWSERS_PATH and the real HOME is the only place the browser
     * exists. Production pins that variable in the image, so there the override
     * was harmless and the bug stayed invisible.
     *
     * So: only move HOME when the one we have cannot do the job. A writable HOME
     * is exactly what the crash handler needs and exactly what Playwright needs
     * left alone.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $home = (string) getenv('HOME');

        if ($home !== '' && is_writable($home)) {
            return [];
        }

        return ['HOME' => sys_get_temp_dir()];
    }
}
