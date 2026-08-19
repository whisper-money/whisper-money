<?php

namespace App\Http\Controllers;

use App\Support\Marketing\AgentMarkdown;
use App\Support\Marketing\IntegrationsPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public list of banks and apps that can be connected, one URL per language.
 *
 * A visitor should be able to answer "is my bank here?" before registering, so
 * the bank names are rendered server-side; the filter on top of them only hides
 * what is already in the document.
 */
class IntegrationsController extends Controller
{
    public function show(Request $request): Response
    {
        $locale = ComparisonController::routeLocale($request);

        App::setLocale($locale);

        $alternates = IntegrationsPage::alternates();

        $response = Inertia::render('integrations', [
            'content' => IntegrationsPage::content($locale),
            'countries' => IntegrationsPage::countries($locale),
            'labels' => IntegrationsPage::labels($locale),
            'pageLocale' => $locale,
            'alternates' => $alternates,
            'canRegister' => config('auth.registration_enabled'),
        ])->toResponse($request);

        $response->headers->set(
            'Link',
            ComparisonController::seoLinks(IntegrationsPage::url($locale), $alternates),
            false,
        );

        return $response;
    }

    public function markdown(Request $request): HttpResponse
    {
        $locale = ComparisonController::routeLocale($request);

        App::setLocale($locale);

        return ComparisonController::respondWithMarkdown(
            AgentMarkdown::integrations($locale),
            IntegrationsPage::url($locale),
        );
    }
}
