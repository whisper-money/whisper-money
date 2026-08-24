<?php

namespace App\Http\Controllers;

use App\Support\Marketing\AgentMarkdown;
use App\Support\Marketing\ComparisonPages;
use App\Support\Marketing\MarketingContent;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public competitor comparison landings, one URL per language.
 *
 * The language is pinned by the URL rather than by the visitor's session, so the
 * page a crawler indexes is the page it was served, and the chrome around the
 * copy is in the same language as the copy.
 */
class ComparisonController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $locale = self::routeLocale($request);
        $page = ComparisonPages::find($locale, $slug);

        abort_if($page === null, 404);

        App::setLocale($locale);

        $alternates = ComparisonPages::alternates($page['key']);

        $response = Inertia::render('comparison', [
            'page' => $page,
            'labels' => MarketingContent::labels($locale),
            'pageLocale' => $locale,
            'alternates' => $alternates,
            'canRegister' => config('auth.registration_enabled'),
        ])->toResponse($request);

        // The page also declares these in its head, but that head is rendered by
        // the SSR process; the header is what survives if SSR is unavailable and
        // the page falls back to rendering in the browser.
        $response->headers->set('Link', self::seoLinks(ComparisonPages::url($locale, $slug), $alternates), false);

        return $response;
    }

    public function markdown(Request $request, string $slug): HttpResponse
    {
        $locale = self::routeLocale($request);
        $page = ComparisonPages::find($locale, $slug);

        abort_if($page === null, 404);

        App::setLocale($locale);

        return self::respondWithMarkdown(
            AgentMarkdown::comparison($page, $locale),
            ComparisonPages::url($locale, $slug),
        );
    }

    /**
     * Canonical, the other languages of this page, and its Markdown twin, in the
     * form Google accepts as an alternative to markup in the head. Shared with
     * the integrations page, which needs exactly the same header.
     *
     * @param  array<string, string>  $alternates
     */
    public static function seoLinks(string $canonical, array $alternates): string
    {
        $links = ['<'.$canonical.'>; rel="canonical"'];

        foreach ($alternates as $alternateLocale => $url) {
            $links[] = '<'.$url.'>; rel="alternate"; hreflang="'.$alternateLocale.'"';
        }

        $links[] = '<'.$alternates['en'].'>; rel="alternate"; hreflang="x-default"';
        $links[] = '<'.$canonical.'.md>; rel="alternate"; type="text/markdown"';

        return implode(', ', $links);
    }

    /**
     * The language this route publishes, declared as a route default rather than
     * as a URI segment because each language has its own base path. Route
     * defaults are read explicitly: they are not injected into method arguments
     * by name, and a positional bind would hand us the slug instead.
     */
    public static function routeLocale(Request $request): string
    {
        return (string) ($request->route()?->defaults['locale'] ?? 'en');
    }

    /**
     * Markdown carries a canonical pointing at the HTML page it mirrors, so the
     * two are not read as competing duplicates of each other.
     */
    public static function respondWithMarkdown(string $body, string $canonical): HttpResponse
    {
        return response($body, 200)
            ->header('Content-Type', 'text/markdown; charset=UTF-8')
            ->header('Link', '<'.$canonical.'>; rel="canonical"');
    }
}
