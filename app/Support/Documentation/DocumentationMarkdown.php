<?php

namespace App\Support\Documentation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns a documentation page's Markdown into the HTML the page renders and the
 * heading list its "on this page" rail is built from, and into the plain
 * Markdown served at the same URL with a `.md` suffix for agents.
 *
 * @phpstan-type Heading array{level: int, title: string, id: string, number: string}
 */
final class DocumentationMarkdown
{
    private const MARKDOWN_OPTIONS = ['html_input' => 'strip', 'allow_unsafe_links' => false];

    /**
     * The line inside a mermaid block that names the drawing the page shows in
     * its place, as a mermaid comment so the block still renders anywhere else.
     */
    private const DIAGRAM_MARKER = '%% diagram:';

    /**
     * Screenshots are taken at twice the size they are shown at, by
     * scripts/docs-screenshots.mjs, so that they stay sharp on a high-density
     * screen. Telling the browser the size it will really paint them at is what
     * makes that work, and it stops the page reflowing as they load.
     */
    private const SCREENSHOT_DENSITY = 2;

    /**
     * @param  list<int>  $levels  heading levels that belong in the contents
     * @return array{html: string, headings: list<Heading>}
     */
    public static function render(string $markdown, array $levels): array
    {
        $diagrams = self::extractDiagramBlocks($markdown);
        $cards = self::extractCardBlocks($diagrams['markdown']);
        $headings = self::headings($markdown, $levels);
        $html = (string) Str::of(self::withoutTocPlaceholder($cards['markdown']))->markdown(self::MARKDOWN_OPTIONS);
        $html = self::replacePlaceholders($html, [...$cards['html'], ...$diagrams['html']]);

        return [
            'html' => self::withThemedImages(self::addHeadingIds($html, $headings, $levels)),
            'headings' => $headings,
        ];
    }

    /**
     * The page as Markdown for agents: the source, minus the placeholder the
     * HTML page fills in and the wrappers that only mean something to it.
     */
    public static function forAgents(string $markdown): string
    {
        $lines = [];

        foreach (preg_split('/\R/', self::withAbsoluteUrls(self::withoutTocPlaceholder($markdown))) ?: [] as $line) {
            if (! self::isLayoutMarkup(trim($line))) {
                $lines[] = $line;
            }
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '')."\n";
    }

    private static function isLayoutMarkup(string $line): bool
    {
        return in_array($line, ['<div class="cards-wrapper">', '<div class="card">', '</div>'], true)
            || Str::startsWith($line, self::DIAGRAM_MARKER);
    }

    /**
     * A page's mermaid block stays in the Markdown, because for an agent reading
     * the `.md` twin it is the best description of the diagram there is. The
     * page itself shows the drawing that was made for that block instead, named
     * by the `%% diagram:` line inside it. The block is lifted out before the
     * Markdown is rendered — raw HTML is stripped — and the SVG goes back in
     * once the page around it is HTML.
     *
     * @return array{markdown: string, html: array<string, string>}
     */
    private static function extractDiagramBlocks(string $markdown): array
    {
        $diagrams = [];

        $replaced = preg_replace_callback(
            '/^```mermaid\R(.*?)^```$/ms',
            function (array $match) use (&$diagrams): string {
                $svg = self::diagramSvg($match[1]);

                if ($svg === null) {
                    return $match[0];
                }

                $placeholder = 'DOCUMENTATION_DIAGRAM_'.count($diagrams);
                $diagrams[$placeholder] = '<div class="documentation-diagram">'.$svg.'</div>';

                return $placeholder;
            },
            $markdown,
        );

        return ['markdown' => $replaced ?? $markdown, 'html' => $diagrams];
    }

    /**
     * The drawing a mermaid block names, when it names one that has been drawn.
     * Anything else keeps the block as it is written.
     */
    private static function diagramSvg(string $definition): ?string
    {
        if (preg_match('/^[ \t]*'.preg_quote(self::DIAGRAM_MARKER, '/').'[ \t]*([a-z0-9-]+)[ \t]*$/m', $definition, $match) !== 1) {
            return null;
        }

        $file = resource_path('docs/diagrams/'.$match[1].'.svg');

        return File::exists($file) ? trim(File::get($file)) : null;
    }

    /**
     * A screenshot is written once in the Markdown and rendered twice: the file
     * itself for light mode and its `-dark` twin for dark, with CSS showing one
     * of them. The app switches theme with a class on <html> and offers a
     * "system" setting, so a <picture> keyed on prefers-color-scheme would hand
     * the wrong screenshot to anyone who has chosen a theme of their own.
     *
     * A page whose dark twin has not been taken points both copies at the light
     * file, which keeps one code path and one CSS rule instead of a third case.
     */
    private static function withThemedImages(string $html): string
    {
        return (string) preg_replace_callback(
            '/<img src="([^"]*)" alt="([^"]*)"\s*\/?>/',
            function (array $match): string {
                $light = $match[1];
                $dark = self::darkTwin($light) ?? $light;

                return self::image($light, $match[2], 'documentation-shot--light')
                    .self::image($dark, $match[2], 'documentation-shot--dark');
            },
            $html,
        );
    }

    /**
     * The source and the alt text come out of the rendered HTML already escaped,
     * so they go back in as they are rather than through e() twice.
     */
    private static function image(string $src, string $alt, string $variant): string
    {
        return '<img src="'.$src.'" alt="'.$alt.'" class="documentation-shot '.$variant.'"'.self::dimensions($src).' loading="lazy" decoding="async">';
    }

    /**
     * The size the browser should paint a screenshot at, when it is one of ours
     * and can be measured. An external or missing image simply goes without.
     */
    private static function dimensions(string $src): string
    {
        $path = public_path(ltrim($src, '/'));
        $size = File::exists($path) ? getimagesize($path) : false;

        if ($size === false) {
            return '';
        }

        return sprintf(
            ' width="%d" height="%d"',
            (int) round($size[0] / self::SCREENSHOT_DENSITY),
            (int) round($size[1] / self::SCREENSHOT_DENSITY),
        );
    }

    /**
     * `screenshot.png` -> `screenshot-dark.png`, when that file has been taken.
     */
    private static function darkTwin(string $src): ?string
    {
        $dark = preg_replace('/(\.[a-z0-9]+)$/i', '-dark$1', $src);

        if ($dark === null || $dark === $src) {
            return null;
        }

        return File::exists(public_path(ltrim($dark, '/'))) ? $dark : null;
    }

    /**
     * Links and image paths become absolute, so a page an agent has read on its
     * own is enough to reach the pages it points at and the screenshots it
     * embeds. Anything already absolute, and any anchor, is left alone.
     */
    private static function withAbsoluteUrls(string $markdown): string
    {
        return (string) preg_replace_callback(
            '/\]\((\/[^)\s]*)\)/',
            fn (array $match): string => ']('.url($match[1]).')',
            $markdown,
        );
    }

    private static function withoutTocPlaceholder(string $markdown): string
    {
        $placeholder = (string) config('documentation.toc.placeholder', '{{TOC}}');

        return $placeholder === '' ? $markdown : str_replace($placeholder, '', $markdown);
    }

    /**
     * Card blocks are HTML islands in the Markdown, so they are lifted out,
     * rendered on their own and put back once the page around them is HTML.
     *
     * @return array{markdown: string, html: array<string, string>}
     */
    private static function extractCardBlocks(string $markdown): array
    {
        $cardBlocks = [];
        $output = [];
        $index = 0;
        $insideWrapper = false;
        $insideCard = false;
        $wrapperCards = [];
        $cardLines = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (! $insideWrapper) {
                if (trim($line) === '<div class="cards-wrapper">') {
                    $insideWrapper = true;
                    $wrapperCards = [];
                } else {
                    $output[] = $line;
                }

                continue;
            }

            if (! $insideCard && trim($line) === '<div class="card">') {
                $insideCard = true;
                $cardLines = [];

                continue;
            }

            if (trim($line) !== '</div>') {
                if ($insideCard) {
                    $cardLines[] = $line;
                }

                continue;
            }

            if ($insideCard) {
                $insideCard = false;
                $wrapperCards[] = trim(implode("\n", $cardLines));
                $cardLines = [];

                continue;
            }

            $placeholder = "DOCUMENTATION_CARDS_{$index}";
            $cardBlocks[$placeholder] = self::cardsHtml($wrapperCards);
            $output[] = $placeholder;
            $insideWrapper = false;
            $wrapperCards = [];
            $index++;
        }

        return ['markdown' => implode("\n", $output), 'html' => $cardBlocks];
    }

    /**
     * @param  array<int, string>  $cards
     */
    private static function cardsHtml(array $cards): string
    {
        $items = collect($cards)
            ->filter()
            ->map(fn (string $card): string => '<section class="card">'.(string) Str::of($card)->markdown(self::MARKDOWN_OPTIONS).'</section>')
            ->implode('');

        return $items === '' ? '' : '<div class="cards-wrapper">'.$items.'</div>';
    }

    /**
     * @param  array<string, string>  $blocks
     */
    private static function replacePlaceholders(string $html, array $blocks): string
    {
        foreach ($blocks as $placeholder => $blockHtml) {
            $html = str_replace(["<p>{$placeholder}</p>", $placeholder], $blockHtml, $html);
        }

        return $html;
    }

    /**
     * The headings that make up the contents of a page, numbered.
     *
     * @param  list<int>  $levels
     * @return list<Heading>
     */
    public static function headings(string $markdown, array $levels): array
    {
        preg_match_all('/^(#{1,6})\s+(.+?)\s*#*\s*$/m', $markdown, $matches, PREG_SET_ORDER);

        $headings = [];
        $usedSlugs = [];

        foreach ($matches as $match) {
            $level = strlen($match[1]);

            if (! in_array($level, $levels, true)) {
                continue;
            }

            $title = self::plainHeadingText($match[2]);

            $headings[] = [
                'level' => $level,
                'title' => $title,
                'id' => self::uniqueHeadingId($title, $usedSlugs),
            ];
        }

        return self::numbered($headings, $levels);
    }

    private static function plainHeadingText(string $heading): string
    {
        $html = (string) Str::of($heading)->inlineMarkdown(self::MARKDOWN_OPTIONS);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, int>  $usedSlugs
     */
    private static function uniqueHeadingId(string $title, array &$usedSlugs): string
    {
        $base = Str::slug($title) ?: 'section';
        $usedSlugs[$base] = ($usedSlugs[$base] ?? 0) + 1;

        return $usedSlugs[$base] === 1 ? $base : "{$base}-{$usedSlugs[$base]}";
    }

    /**
     * Section numbers, so the contents reads 1, 2, 2.1, 2.2, 3 rather than as a
     * flat list of every heading on the page.
     *
     * @param  list<array{level: int, title: string, id: string}>  $headings
     * @param  list<int>  $levels
     * @return list<Heading>
     */
    private static function numbered(array $headings, array $levels): array
    {
        $counts = [];
        $numbered = [];

        foreach ($headings as $heading) {
            foreach ($levels as $level) {
                if ($level > $heading['level']) {
                    unset($counts[$level]);
                }

                if ($level < $heading['level'] && ! isset($counts[$level])) {
                    $counts[$level] = 1;
                }
            }

            $counts[$heading['level']] = ($counts[$heading['level']] ?? 0) + 1;

            $number = collect($levels)
                ->filter(fn (int $level): bool => $level <= $heading['level'] && isset($counts[$level]))
                ->map(fn (int $level): int => $counts[$level])
                ->implode('.');

            $numbered[] = [...$heading, 'number' => $number];
        }

        return $numbered;
    }

    /**
     * @param  list<Heading>  $headings
     * @param  list<int>  $levels
     */
    private static function addHeadingIds(string $html, array $headings, array $levels): string
    {
        if ($headings === [] || $levels === []) {
            return $html;
        }

        $pattern = '/<h(['.implode('', $levels).'])>(.*?)<\/h\1>/s';
        $index = 0;

        return (string) preg_replace_callback(
            $pattern,
            function (array $match) use ($headings, &$index): string {
                $heading = $headings[$index] ?? null;
                $index++;

                if ($heading === null) {
                    return $match[0];
                }

                return sprintf('<h%d id="%s">%s</h%d>', (int) $match[1], e($heading['id']), $match[2], (int) $match[1]);
            },
            $html,
        );
    }
}
