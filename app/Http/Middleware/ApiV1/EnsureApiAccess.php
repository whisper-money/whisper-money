<?php

namespace App\Http\Middleware\ApiV1;

use App\Enums\PlanFeature;
use App\Http\ApiV1\ApiV1Exception;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `/api/v1` plan gate. A lapsed subscription is answered with a 403 and
 * the subscribe link, without revoking any token, so access comes back on
 * resubscribing. Under OAuth the plan that counts is the authorizing user's.
 *
 * The shared demo and press accounts are refused outright: their credentials
 * are public and their data is everybody's.
 */
class EnsureApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiV1Exception::unauthenticated();
        }

        if ($user->isRestrictedSharedAccount()) {
            throw new ApiV1Exception(403, 'shared_account', 'The API is not available on a shared account.');
        }

        if (! $user->canUseFeature(PlanFeature::ApiAccess)) {
            throw new ApiV1Exception(
                403,
                'plan_required',
                'A paid (Pro) plan is required to use the Whisper Money API.',
                ['subscribe_url' => route('subscribe')],
            );
        }

        return $next($request);
    }
}
