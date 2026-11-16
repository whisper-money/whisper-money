<?php

namespace App\Http\Middleware;

use App\Features\CustomDashboards;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the custom dashboards and widgets routes to a user without the
 * CustomDashboards flag, before validation or anything else runs: the
 * policies and form requests check the flag too, but a request that never
 * reached them (a form request that validates before the controller
 * authorizes) would otherwise answer with its validation errors.
 */
class EnsureCustomDashboards
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && Feature::for($user)->active(CustomDashboards::class), 403);

        return $next($request);
    }
}
