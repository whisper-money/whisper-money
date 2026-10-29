<?php

namespace App\Http\Middleware\ApiV1;

use App\Enums\ApiCredential;
use App\Models\ApiRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage metric (see `stats:api-usage`): one row per authenticated request,
 * whatever its outcome, so the gate's 403s and the throttle's 429s show up
 * too. Runs right after authentication; rescued so a failed insert can never
 * break a working request.
 */
class RecordApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $credential = $request->attributes->get(ApiCredential::class);
        $user = $request->user();

        if ($credential instanceof ApiCredential && $user !== null) {
            rescue(fn (): ApiRequest => ApiRequest::create([
                'user_id' => $user->getAuthIdentifier(),
                'method' => $request->method(),
                'endpoint' => $request->route()?->getName() ?? $request->route()?->uri() ?? $request->path(),
                'credential' => $credential,
                'oauth_client_id' => $request->attributes->get('oauth_client_id'),
                'status' => $response->getStatusCode(),
            ]));
        }

        return $response;
    }
}
