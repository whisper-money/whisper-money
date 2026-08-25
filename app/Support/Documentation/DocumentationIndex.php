<?php

namespace App\Support\Documentation;

/**
 * The Markdown an agent is handed to find its way around: the index served at
 * /documentation.md, and the footer every page carries so a page reached on its
 * own still leads somewhere.
 *
 * The tree comes from the files, so a page added to the documentation appears
 * here without anyone remembering to list it.
 */
final class DocumentationIndex
{
    /**
     * Every page, grouped the way the sidebar groups them and indented the way
     * they are nested, each one pointing at its own Markdown.
     */
    public static function markdown(DocumentationTree $tree, string $locale): string
    {
        $labels = self::labels($locale);

        $body = implode("\n", [
            '# '.$labels['title'],
            '',
            $labels['intro'],
            '',
            ...self::lines($tree->nodes(), $locale, 0),
        ]);

        return trim(preg_replace('/\n{3,}/', "\n\n", $body) ?? '')."\n";
    }

    /**
     * What a page ends with when an agent reads it: the pages nested under it,
     * so the hierarchy is walkable from any page, and the index, so everything
     * else is one fetch away.
     *
     * @param  array<string, mixed>  $page
     */
    public static function footer(array $page, string $locale): string
    {
        $labels = self::labels($locale);
        /** @var list<array<string, mixed>> $children */
        $children = $page['children'];
        $lines = ['', '---', ''];

        if ($children !== []) {
            $lines[] = $labels['children'];
            $lines[] = '';

            foreach ($children as $child) {
                $lines[] = self::entry($child, $locale, 0);
            }

            $lines[] = '';
        }

        return implode("\n", [
            ...$lines,
            $labels['index'].' '.route('documentation.index.markdown', ['lang' => $locale]),
            '',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private static function lines(array $nodes, string $locale, int $depth): array
    {
        $lines = [];

        foreach ($nodes as $node) {
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];

            if ($node['type'] === 'section') {
                $lines = [...$lines, '', '## '.$node['title'], '', ...self::lines($children, $locale, 0), ''];

                continue;
            }

            $lines[] = self::entry($node, $locale, $depth);
            $lines = [...$lines, ...self::lines($children, $locale, $depth + 1)];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private static function entry(array $page, string $locale, int $depth): string
    {
        return str_repeat('  ', $depth).'- ['.$page['title'].']('.self::url((string) $page['slug'], $locale).'): '.$page['description'];
    }

    private static function url(string $slug, string $locale): string
    {
        return route('documentation.markdown', ['slug' => $slug, 'lang' => $locale]);
    }

    /**
     * @return array{title: string, intro: string, children: string, index: string}
     */
    private static function labels(string $locale): array
    {
        return $locale === 'es' ? [
            'title' => 'Documentación de Whisper Money',
            'intro' => 'Todas las páginas de la documentación. Cada una está también en HTML en la misma dirección, sin el sufijo `.md`.',
            'children' => 'Páginas dentro de esta:',
            'index' => 'Índice completo de la documentación:',
        ] : [
            'title' => 'Whisper Money documentation',
            'intro' => 'Every page in the documentation. Each one is also served as HTML at the same address, without the `.md` suffix.',
            'children' => 'Pages inside this one:',
            'index' => 'Full documentation index:',
        ];
    }
}
