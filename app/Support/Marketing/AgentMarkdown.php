<?php

namespace App\Support\Marketing;

use App\Support\Documentation\DocumentationTree;

/**
 * Markdown representations of the marketing pages, for agents and for any tool
 * that would rather read prose than parse a rendered React page.
 *
 * They are generated from the same content the HTML pages render, so the two can
 * never disagree, and each one points back at its HTML canonical.
 */
final class AgentMarkdown
{
    /**
     * A comparison page as Markdown.
     *
     * @param  array<string, mixed>  $page
     */
    public static function comparison(array $page, string $locale): string
    {
        $labels = MarketingContent::labels($locale);
        $rival = $page['rival'];

        $lines = [
            '# '.$page['heading'],
            '',
            '> '.$page['description'],
            '',
            $page['intro'],
            '',
            '## '.$labels['head_to_head'],
            '',
            '| '.$labels['dimension'].' | '.$rival.' | Whisper Money |',
            '| --- | --- | --- |',
        ];

        foreach ($page['rows'] as $row) {
            $lines[] = '| '.self::cell($row['dimension']).' | '.self::cell($row['rival']).' | '.self::cell($row['whisper']).' |';
        }

        $lines = [...$lines, '', '## '.$page['narrative_title'], ''];

        foreach ($page['narrative'] as $paragraph) {
            $lines = [...$lines, $paragraph, ''];
        }

        $lines[] = '## '.str_replace(':rival', $rival, $labels['changes']);
        $lines[] = '';

        foreach ($page['bullets'] as $bullet) {
            $lines[] = '- '.$bullet;
        }

        $lines = [
            ...$lines,
            '',
            '## '.str_replace(':rival', $rival, $labels['migration']),
            '',
            $page['migration_intro'],
            '',
        ];

        foreach ($page['migration_steps'] as $index => $step) {
            $lines = [...$lines, ($index + 1).'. **'.$step['title'].'** — '.$step['body'], ''];
        }

        $lines = [...$lines, '## '.$labels['testimonials'], ''];

        foreach ($page['testimonials'] as $quote) {
            $lines = [...$lines, '> '.$quote['text'], '>', '> — '.$quote['name'], ''];
        }

        return implode("\n", [
            ...$lines,
            '## '.str_replace(':rival', $rival, $labels['closing']),
            '',
            $page['closing_body'],
            '',
        ]);
    }

    /**
     * The documentation, page by page, in every language it is written in. The
     * tree comes from the files, so a page added to the docs shows up here
     * without anyone remembering to list it.
     *
     * @return list<string>
     */
    private static function documentationIndex(): array
    {
        $lines = [];

        foreach (array_keys((array) config('documentation.locales', [])) as $locale) {
            $pages = DocumentationTree::for((string) $locale)->pages();

            if ($pages === []) {
                continue;
            }

            $lines = [...$lines, '## '.($locale === 'es' ? 'Documentación (español)' : 'Documentation (English)'), ''];

            foreach ($pages as $page) {
                $url = route('documentation.show', ['slug' => $page['slug'], 'lang' => $locale]);
                $markdown = route('documentation.markdown', ['slug' => $page['slug'], 'lang' => $locale]);

                $lines[] = '- ['.$page['title'].']('.$markdown.'): '.$page['description'].' Also at '.$url;
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * The integrations page as Markdown: the same list the HTML page renders,
     * in the shape an agent asked "does it support my bank" can read.
     */
    public static function integrations(string $locale): string
    {
        $content = IntegrationsPage::content($locale);

        $lines = [
            '# '.$content['heading'],
            '',
            '> '.$content['description'],
            '',
            $content['intro'],
            '',
            '## '.$content['banks_title'],
            '',
            $content['banks_intro'],
            '',
            $content['banks_note'],
            '',
        ];

        foreach (IntegrationsPage::countries($locale) as $country) {
            $names = array_map(
                // A key marks what connects with a code the user creates, the
                // same distinction the page draws with an icon.
                fn (array $entry): string => $entry['key'] ? $entry['name'].' (key)' : $entry['name'],
                $country['entries'],
            );

            $lines = [
                ...$lines,
                '### '.$country['name'].' ('.$country['code'].')',
                '',
                implode(', ', $names),
                '',
            ];
        }

        $lines = [
            ...$lines,
            '## '.$content['importer_title'],
            '',
            $content['importer_body'],
            '',
            '## '.$content['notes_title'],
            '',
        ];

        foreach ($content['notes'] as $note) {
            $lines[] = '- '.$note;
        }

        return implode("\n", [
            ...$lines,
            '',
            '## '.$content['closing_title'],
            '',
            $content['closing_body'],
            '',
        ]);
    }

    /**
     * The product summary as Markdown.
     */
    public static function landing(string $locale): string
    {
        $landing = MarketingContent::landing($locale);

        $lines = ['# '.$landing['title'], '', $landing['summary'], ''];

        foreach ($landing['sections'] as $heading => $points) {
            $lines = [...$lines, '## '.$heading, ''];

            foreach ($points as $point) {
                $lines[] = '- '.$point;
            }

            $lines[] = '';
        }

        return implode("\n", [...$lines, ...self::comparisonIndex($locale)]);
    }

    /**
     * llms.txt: the site's own list of what exists and where, so a crawler does
     * not have to guess at URLs.
     */
    public static function llms(): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $lines = [
            '# Whisper Money',
            '',
            '> '.MarketingContent::landing('en')['summary'],
            '',
            '## Product',
            '',
            '- [Product summary]('.$baseUrl.'/index.md): what Whisper Money does, what it does not do, pricing and platforms.',
            '- [Whisper Money]('.$baseUrl.'/): the landing page.',
            '- [Roadmap]('.$baseUrl.'/roadmap): what is planned, in progress and shipped.',
            '- [Privacy policy]('.$baseUrl.'/privacy)',
            '- [Terms of service]('.$baseUrl.'/terms)',
        ];

        foreach (MarketingContent::LOCALES as $locale) {
            $link = IntegrationsPage::link($locale);

            $lines[] = '- ['.$link['heading'].']('.$link['url'].'): every bank and app that can be connected, in '.($locale === 'es' ? 'Spanish' : 'English').'. Also at '.$link['url'].'.md';
        }

        $lines[] = '';

        foreach (MarketingContent::LOCALES as $locale) {
            $lines = [
                ...$lines,
                '## '.($locale === 'es' ? 'Comparativas (español)' : 'Comparisons (English)'),
                '',
            ];

            foreach (ComparisonPages::index($locale) as $page) {
                $lines[] = '- ['.$page['heading'].']('.$page['url'].'.md): '.$page['url'];
            }

            $lines[] = '';
        }

        return implode("\n", [
            ...$lines,
            ...self::documentationIndex(),
            '## Notes',
            '',
            '- Every page is available as Markdown by appending `.md` to its URL.',
            '- Claims about competitors are quoted from those competitors\' own public pages, with the date they were checked stated in the page.',
            '- Data is stored unencrypted at rest in our own database; the code is public at https://github.com/whisper-money/whisper-money.',
            '',
        ]);
    }

    /**
     * Links to the comparison pages in this language, appended to the summary so
     * an agent reading one document can reach the rest.
     *
     * @return list<string>
     */
    private static function comparisonIndex(string $locale): array
    {
        $lines = [$locale === 'es' ? '## Comparativas' : '## Comparisons', ''];

        foreach (ComparisonPages::index($locale) as $page) {
            $lines[] = '- ['.$page['heading'].']('.$page['url'].')';
        }

        return [...$lines, ''];
    }

    /**
     * Table cells cannot carry the pipe that would end them early.
     */
    private static function cell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
