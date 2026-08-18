<?php

namespace App\Http\Controllers;

use App\Support\Marketing\AgentMarkdown;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Plain-text documents written for agents and crawlers rather than for people:
 * llms.txt as the index of what exists, and a Markdown product summary.
 */
class AgentDocsController extends Controller
{
    public function llms(): Response
    {
        return response(AgentMarkdown::llms(), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function landing(Request $request): Response
    {
        $locale = ComparisonController::routeLocale($request);

        App::setLocale($locale);

        $baseUrl = rtrim((string) config('app.url'), '/');

        return ComparisonController::respondWithMarkdown(
            AgentMarkdown::landing($locale),
            $locale === 'en' ? $baseUrl.'/' : $baseUrl.'/?lang='.$locale,
        );
    }
}
