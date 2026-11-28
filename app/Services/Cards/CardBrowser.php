<?php

namespace App\Services\Cards;

use App\Services\Browser\HeadlessChromium;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Photographs HTML at a size and stores the PNG where the caller asks.
 *
 * This is the half of the card pipeline that knows nothing about what it is
 * drawing — one headless Chromium, a batch of pages, a PNG each. Monthly
 * summaries and achievement medals both come through here, so the parts that
 * were painful to get right (the batch timeout, keeping the cards that
 * rendered when one of them dies) are solved once; the subprocess itself
 * (the crashpad HOME) is {@see HeadlessChromium}'s.
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

    public function __construct(private HeadlessChromium $chromium) {}

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
            $result = $this->chromium->run(
                'scripts/render-card.mjs',
                $manifest,
                self::TIMEOUT_SECONDS + self::TIMEOUT_PER_CARD_SECONDS * count($pages),
            );

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
}
