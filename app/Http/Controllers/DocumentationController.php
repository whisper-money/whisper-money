<?php

namespace App\Http\Controllers;

use App\Support\Documentation\DocumentationMarkdown;
use App\Support\Documentation\DocumentationTree;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public product documentation.
 *
 * The pages, their order and their nesting all come from the file layout under
 * resources/docs/documentation rather than from configuration, so writing a page
 * is adding a Markdown file. Each page is served as HTML for people and, at the
 * same URL with a `.md` suffix, as Markdown for agents.
 */
class DocumentationController extends Controller
{
    public function show(Request $request, ?string $slug = null): Response
    {
        $locale = $this->locale();
        $tree = DocumentationTree::for($locale);
        $page = $this->page($tree, $slug);
        $levels = $this->tocLevels();
        $rendered = DocumentationMarkdown::render(File::get($page['file']), $levels);
        $languages = $this->languageLinks($page['slug'], $locale);

        $response = Inertia::render('documentation/show', [
            'document' => [
                'slug' => $page['slug'],
                'locale' => $locale,
                'title' => $page['title'],
                'description' => $page['description'],
                'html' => $rendered['html'],
                'headings' => $rendered['headings'],
                'markdownUrl' => $this->markdownUrl($page['slug'], $locale),
            ],
            'navigation' => $this->navigation($tree->nodes(), $page['slug'], $locale),
            'searchIndex' => $this->searchIndex($tree, $locale, $levels),
            'neighbours' => $this->neighbours($tree, $page['slug'], $locale),
            'languages' => $languages,
        ])->toResponse($request);

        $response->headers->set('Link', ComparisonController::seoLinks(
            $this->pageUrl($page['slug'], $locale),
            $this->alternates($page['slug']),
            $this->pageUrl($page['slug'], $locale, markdown: true),
        ), false);

        return $response;
    }

    /**
     * The same page as Markdown, for agents and for anything that would rather
     * read the source than parse the rendered page.
     */
    public function markdown(?string $slug = null): HttpResponse
    {
        $locale = $this->locale();
        $page = $this->page(DocumentationTree::for($locale), $slug);

        return ComparisonController::respondWithMarkdown(
            DocumentationMarkdown::forAgents(File::get($page['file'])),
            $this->pageUrl($page['slug'], $locale),
        );
    }

    /**
     * @return array{type: 'page', slug: string, order: int, title: string, description: string, file: string, children: list<mixed>}
     */
    private function page(DocumentationTree $tree, ?string $slug): array
    {
        $page = $tree->find($slug ?? (string) config('documentation.default'));

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return $page;
    }

    private function locale(): string
    {
        $locale = App::currentLocale();

        return array_key_exists($locale, $this->supportedLocales()) ? $locale : $this->fallbackLocale();
    }

    private function fallbackLocale(): string
    {
        $locale = config('documentation.fallback_locale', 'en');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * @return array<string, string>
     */
    private function supportedLocales(): array
    {
        $locales = config('documentation.locales', []);

        if (! is_array($locales)) {
            return [];
        }

        return collect($locales)
            ->mapWithKeys(fn (mixed $label, string $locale): array => [$locale => (string) $label])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function tocLevels(): array
    {
        $levels = config('documentation.toc.levels', [2, 3]);

        if (! is_array($levels)) {
            return [2, 3];
        }

        return collect($levels)
            ->map(fn (mixed $level): int => (int) $level)
            ->filter(fn (int $level): bool => $level >= 1 && $level <= 6)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The tree the sidebar renders: sections, their pages, and the pages nested
     * under a page. A branch is marked expanded when the page being read is
     * somewhere inside it, so the sidebar opens on what you are looking at.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function navigation(array $nodes, string $activeSlug, string $locale): array
    {
        $navigation = [];

        foreach ($nodes as $node) {
            /** @var list<array<string, mixed>> $nodeChildren */
            $nodeChildren = $node['children'];
            $children = $this->navigation($nodeChildren, $activeSlug, $locale);
            $hasActiveChild = collect($children)->contains(fn (array $child): bool => $child['active'] === true || $child['expanded'] === true);

            if ($node['type'] === 'section') {
                $navigation[] = [
                    'type' => 'section',
                    'key' => 'section-'.$node['id'],
                    'title' => $node['title'],
                    'active' => false,
                    'expanded' => $hasActiveChild,
                    'children' => $children,
                ];

                continue;
            }

            $active = $node['slug'] === $activeSlug;

            $navigation[] = [
                'type' => 'page',
                'key' => 'page-'.$node['slug'],
                'slug' => $node['slug'],
                'title' => $node['title'],
                'url' => $this->pagePath($node['slug'], $locale),
                'active' => $active,
                'expanded' => $active || $hasActiveChild,
                'children' => $children,
            ];
        }

        return $navigation;
    }

    /**
     * Every page and every section heading in one list, so the search box can
     * answer without a round trip. The documentation is a handful of files, and
     * this is the whole of it.
     *
     * @param  list<int>  $levels
     * @return list<array{slug: string, title: string, url: string, headings: list<array{title: string, id: string}>}>
     */
    private function searchIndex(DocumentationTree $tree, string $locale, array $levels): array
    {
        return collect($tree->pages())
            ->map(fn (array $page): array => [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'url' => $this->pagePath($page['slug'], $locale),
                'headings' => collect(DocumentationMarkdown::headings(File::get($page['file']), $levels))
                    ->map(fn (array $heading): array => ['title' => $heading['title'], 'id' => $heading['id']])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{previous: array{title: string, url: string}|null, next: array{title: string, url: string}|null}
     */
    private function neighbours(DocumentationTree $tree, string $activeSlug, string $locale): array
    {
        $pages = $tree->pages();
        $position = collect($pages)->search(fn (array $page): bool => $page['slug'] === $activeSlug);

        return [
            'previous' => $this->neighbour($pages[$position - 1] ?? null, $locale),
            'next' => $this->neighbour($pages[$position + 1] ?? null, $locale),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $page
     * @return array{title: string, url: string}|null
     */
    private function neighbour(?array $page, string $locale): ?array
    {
        if ($page === null) {
            return null;
        }

        return ['title' => $page['title'], 'url' => $this->pagePath($page['slug'], $locale)];
    }

    /**
     * @return list<array{locale: string, label: string, url: string, active: bool}>
     */
    private function languageLinks(string $slug, string $activeLocale): array
    {
        return collect($this->supportedLocales())
            ->map(fn (string $label, string $locale): array => [
                'locale' => $locale,
                'label' => $label,
                'url' => $this->pagePath($slug, $locale),
                'active' => $locale === $activeLocale,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function alternates(string $slug): array
    {
        return collect($this->supportedLocales())
            ->map(fn (string $label, string $locale): string => $this->pageUrl($slug, $locale))
            ->all();
    }

    private function pagePath(string $slug, string $locale): string
    {
        return route('documentation.show', ['slug' => $slug, 'lang' => $locale], false);
    }

    private function markdownUrl(string $slug, string $locale): string
    {
        return route('documentation.markdown', ['slug' => $slug, 'lang' => $locale], false);
    }

    private function pageUrl(string $slug, string $locale, bool $markdown = false): string
    {
        return route($markdown ? 'documentation.markdown' : 'documentation.show', ['slug' => $slug, 'lang' => $locale]);
    }
}
