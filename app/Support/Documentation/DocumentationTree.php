<?php

namespace App\Support\Documentation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The documentation navigation, read from the file layout under
 * resources/docs/documentation.
 *
 * The directory structure is the tree, so adding a page is adding a file:
 *
 *     getting-started-en.md              a page at the top level
 *     getting-started-es.md              the same page in Spanish
 *     your-data/section.md               titles the group, one line per language
 *     your-data/accounts-en.md           a page inside the group
 *     your-data/accounts/types-en.md     a page nested under accounts
 *
 * A section groups pages in the sidebar and nothing more, so it stays out of
 * their slugs: the page above is `accounts`. Nesting a page under another page
 * is a real hierarchy and does reach the slug, so its child is
 * `accounts/types`.
 *
 * A leading `10-` orders an entry and is dropped from its slug; without one,
 * entries sort by name. English is the only required language: a page with no
 * `-en.md` is not published, and a language we have no file for falls back to
 * English.
 *
 * @phpstan-type PageNode array{type: 'page', slug: string, order: int, title: string, description: string, file: string, children: list<mixed>}
 * @phpstan-type SectionNode array{type: 'section', id: string, order: int, title: string, children: list<mixed>}
 */
final class DocumentationTree
{
    /**
     * `10-account-types-en.md` — an optional order, the slug, the language.
     */
    private const PAGE_PATTERN = '/^(?:(\d+)[-_])?(.+)-([a-z]{2}(?:[-_][a-z]{2})?)\.md$/i';

    private const DIRECTORY_PATTERN = '/^(?:(\d+)[-_])?(.+)$/';

    private const SECTION_FILE = 'section.md';

    /**
     * `- en: Your data`, or `en: Your data`, inside a section.md.
     */
    private const SECTION_TITLE_PATTERN = '/^\s*[-*]?\s*([a-z]{2}(?:[-_][a-z]{2})?)\s*:\s*(.+?)\s*$/i';

    /** @var list<PageNode|SectionNode>|null */
    private ?array $nodes = null;

    /** @var list<PageNode>|null */
    private ?array $pages = null;

    public function __construct(
        private readonly string $root,
        private readonly string $locale,
        private readonly string $fallbackLocale,
    ) {}

    public static function for(string $locale): self
    {
        return new self(
            (string) config('documentation.root'),
            $locale,
            (string) config('documentation.fallback_locale', 'en'),
        );
    }

    /**
     * The tree in navigation order: pages and sections as they should be listed.
     *
     * @return list<PageNode|SectionNode>
     */
    public function nodes(): array
    {
        return $this->nodes ??= File::isDirectory($this->root) ? $this->scan($this->root, '') : [];
    }

    /**
     * Every page in the tree, flattened in navigation order, so the previous and
     * next page of any page is its neighbour in this list.
     *
     * @return list<PageNode>
     */
    public function pages(): array
    {
        return $this->pages ??= self::flatten($this->nodes());
    }

    /**
     * @return PageNode|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->pages() as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  string  $prefix  the slug of the page these entries hang under,
     *                          with its trailing slash, or '' at the top level
     * @return list<PageNode|SectionNode>
     */
    private function scan(string $directory, string $prefix): array
    {
        $directories = $this->subdirectories($directory);
        $nodes = [];

        foreach ($this->pageGroups($directory) as $name => $group) {
            if (! isset($group['files'][$this->fallbackLocale])) {
                continue;
            }

            $file = $group['files'][$this->locale] ?? $group['files'][$this->fallbackLocale];
            $slug = $prefix.$name;

            $children = $directories[$name]['path'] ?? null;
            unset($directories[$name]);

            $nodes[] = [
                'type' => 'page',
                'slug' => $slug,
                'order' => $group['order'],
                ...$this->metadata($file),
                'file' => $file,
                'children' => $children === null ? [] : $this->scan($children, $slug.'/'),
            ];
        }

        foreach ($directories as $id => $entry) {
            $nodes[] = [
                'type' => 'section',
                'id' => $id,
                'order' => $entry['order'],
                'title' => $this->sectionTitle($entry['path'], $id),
                'children' => $this->scan($entry['path'], $prefix),
            ];
        }

        usort($nodes, fn (array $first, array $second): int => [$first['order'], $first['title']] <=> [$second['order'], $second['title']]);

        return $nodes;
    }

    /**
     * The pages in a directory, one entry per name, holding every language we
     * found a file for. The name is the last segment of the page's slug.
     *
     * @return array<string, array{order: int, files: array<string, string>}>
     */
    private function pageGroups(string $directory): array
    {
        $groups = [];

        foreach (File::files($directory) as $file) {
            if (preg_match(self::PAGE_PATTERN, $file->getFilename(), $matches) !== 1) {
                continue;
            }

            $name = $matches[2];
            $groups[$name] ??= ['order' => self::order($matches[1]), 'files' => []];
            $groups[$name]['files'][self::normalizeLocale($matches[3])] = $file->getPathname();
        }

        return $groups;
    }

    /**
     * @return array<string, array{order: int, path: string}>
     */
    private function subdirectories(string $directory): array
    {
        $entries = [];

        foreach (File::directories($directory) as $path) {
            if (preg_match(self::DIRECTORY_PATTERN, basename($path), $matches) !== 1) {
                continue;
            }

            $entries[$matches[2]] = ['order' => self::order($matches[1]), 'path' => $path];
        }

        return $entries;
    }

    /**
     * A section is titled by its own section.md, and falls back to its directory
     * name so a group without one still reads as something.
     */
    private function sectionTitle(string $directory, string $id): string
    {
        $file = $directory.DIRECTORY_SEPARATOR.self::SECTION_FILE;

        if (! File::exists($file)) {
            return Str::headline($id);
        }

        $titles = self::sectionTitles(File::get($file));

        return $titles[$this->locale] ?? $titles[$this->fallbackLocale] ?? Str::headline($id);
    }

    /**
     * @return array<string, string>
     */
    private static function sectionTitles(string $contents): array
    {
        $titles = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match(self::SECTION_TITLE_PATTERN, $line, $matches) === 1) {
                $titles[self::normalizeLocale($matches[1])] = $matches[2];
            }
        }

        return $titles;
    }

    /**
     * A page names and describes itself: its heading is the title, and the
     * sentence under it is the description search engines are handed.
     *
     * @return array{title: string, description: string}
     */
    private function metadata(string $file): array
    {
        $lines = preg_split('/\R/', File::get($file)) ?: [];
        $title = '';
        $description = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($title === '') {
                if (preg_match('/^#\s+(.+?)\s*#*$/', $line, $matches) === 1) {
                    $title = $matches[1];
                }

                continue;
            }

            if (self::isProse($line)) {
                $description = $line;

                break;
            }
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * The first line under the heading that reads as a sentence, skipping blank
     * lines, further headings, the table of contents placeholder and any block
     * that opens with markup or list syntax.
     */
    private static function isProse(string $line): bool
    {
        if ($line === '' || $line === (string) config('documentation.toc.placeholder', '{{TOC}}')) {
            return false;
        }

        return preg_match('/^[#<>\-*|`\d]/', $line) !== 1;
    }

    /**
     * @param  list<PageNode|SectionNode>  $nodes
     * @return list<PageNode>
     */
    private static function flatten(array $nodes): array
    {
        $pages = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'page') {
                $pages[] = $node;
            }

            /** @var list<PageNode|SectionNode> $children */
            $children = $node['children'];
            $pages = [...$pages, ...self::flatten($children)];
        }

        return $pages;
    }

    /**
     * An entry without a numeric prefix sorts after the ones that have asked for
     * a position, rather than fighting them for the top.
     */
    private static function order(string $prefix): int
    {
        return $prefix === '' ? PHP_INT_MAX : (int) $prefix;
    }

    private static function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('_', '-', $locale));
    }
}
