<?php

namespace App\Support\Documentation;

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
     * @param  list<int>  $levels  heading levels that belong in the contents
     * @return array{html: string, headings: list<Heading>}
     */
    public static function render(string $markdown, array $levels): array
    {
        $cards = self::extractCardBlocks($markdown);
        $headings = self::headings($markdown, $levels);
        $html = (string) Str::of(self::withoutTocPlaceholder($cards['markdown']))->markdown(self::MARKDOWN_OPTIONS);
        $html = self::replaceCardPlaceholders($html, $cards['html']);

        return [
            'html' => self::addHeadingIds($html, $headings, $levels),
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

        foreach (preg_split('/\R/', self::withoutTocPlaceholder($markdown)) ?: [] as $line) {
            if (! self::isLayoutMarkup(trim($line))) {
                $lines[] = $line;
            }
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '')."\n";
    }

    private static function isLayoutMarkup(string $line): bool
    {
        return in_array($line, ['<div class="cards-wrapper">', '<div class="card">', '</div>'], true);
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
     * @param  array<string, string>  $cardBlocks
     */
    private static function replaceCardPlaceholders(string $html, array $cardBlocks): string
    {
        foreach ($cardBlocks as $placeholder => $cardHtml) {
            $html = str_replace(["<p>{$placeholder}</p>", $placeholder], $cardHtml, $html);
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
