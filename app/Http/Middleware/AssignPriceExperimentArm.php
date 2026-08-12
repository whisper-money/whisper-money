<?php

namespace App\Http\Middleware;

use App\Services\Subscriptions\PriceExperiment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draws an anonymous visitor into a price-experiment arm on their first page view
 * and remembers it in a cookie, so the price they are quoted on the landing is the
 * one they will be charged after registering.
 *
 * The drawn arm is written back onto the current request as well as queued on the
 * response: the cookie only reaches the browser after this response, and the very
 * first view — the landing — is the one that has to show the right price.
 */
class AssignPriceExperimentArm
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldDraw($request)) {
            $arm = PriceExperiment::draw();

            $request->cookies->set(PriceExperiment::COOKIE, $arm);
            Cookie::queue(PriceExperiment::COOKIE, $arm, minutes: 60 * 24 * 365);
        }

        return $next($request);
    }

    /**
     * Only anonymous visitors browsing a page are drawn: a signed-in user already
     * carries their arm on the user row. This middleware is in the web group, so
     * API traffic never reaches it.
     */
    private function shouldDraw(Request $request): bool
    {
        return PriceExperiment::isRunning()
            && $request->user() === null
            && $request->isMethod('GET')
            && PriceExperiment::sanitize($request->cookie(PriceExperiment::COOKIE)) === null;
    }
}
