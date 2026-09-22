<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOpenBankingConfigured
{
    /**
     * Guards the routes that can only be served by EnableBanking.
     *
     * Open banking is optional, so a self-hosted install may have no
     * credentials for it. The UI hides the bank connection when that is the
     * case; this is what a request that arrives anyway gets, instead of the
     * container failing to build a provider out of a missing app id.
     *
     * 404 rather than 503: on this install the endpoint genuinely does not
     * exist, and there is nothing the reader can retry.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('services.enablebanking.enabled'), 404);

        return $next($request);
    }
}
