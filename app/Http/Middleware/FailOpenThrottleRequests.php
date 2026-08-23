<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttles like the framework does, but never turns its own bookkeeping into a
 * failed request.
 *
 * The cache store is the database, so recording a hit is two statements against
 * the `cache` table: `insertOrIgnore` for the window's `:timer` row and a
 * `select ... for update` to increment the counter. Two requests from the same
 * user racing each other take those in an order MySQL can deadlock on, and the
 * loser's request dies with a 500 - which is how PHP-LARAVEL-J has been
 * breaking a dashboard chart every day or two, most recently on
 * `/api/cashflow/sankey`. The dashboard fires several of these calls at once, so
 * it is the page that races itself hardest.
 *
 * Letting the request through is the right way to be wrong here. The limit on
 * this group is 300 requests a minute for an authenticated, same-origin API: it
 * exists to stop a runaway client, and one unrecorded hit does not let one
 * through. A chart that fails to draw, on the other hand, is the user's whole
 * reason for being on the page.
 *
 * Narrow on purpose. Only a concurrency error counts, so a genuinely broken
 * cache table still fails loudly; and only before the request has run, so a
 * deadlock raised by the controller's own queries is never swallowed and never
 * replayed.
 *
 * The real fix is a cache store that is not a table. Until then, this.
 */
class FailOpenThrottleRequests extends ThrottleRequests
{
    use DetectsConcurrencyErrors;

    /**
     * @param  array<int, object>  $limits
     */
    protected function handleRequest($request, Closure $next, array $limits): Response
    {
        $requestRan = false;

        $run = function (Request $request) use ($next, &$requestRan) {
            $requestRan = true;

            return $next($request);
        };

        try {
            return parent::handleRequest($request, $run, $limits);
        } catch (\Throwable $e) {
            // Deliberately every throwable, and not `QueryException`: the framework
            // does not declare what its cache store throws, so narrowing here would
            // only be narrowing what static analysis can see. What gets swallowed
            // is decided below, and that condition is the narrow one.
            if ($requestRan || ! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            Log::warning('Rate limiter could not record a hit, letting the request through', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            // Without the parent's remaining-attempts headers: it adds them on the
            // way back out, and we are past that. Nothing reads them.
            return $run($request);
        }
    }
}
