<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\PlanFeature;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

trait HandlesSubscriptionGate
{
    /**
     * A bank connection costs us money with the provider from the moment it is
     * authorized, so it is never opened without a plan behind it — onboarding
     * included. The wizard says so before it gets here: the gate in front of the
     * bank picker sells the plan, and the checkout returns to the picker. This
     * is the same rule enforced where it cannot be talked past.
     */
    private function shouldBlockOpenBankingAccess(User $user): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        return ! $user->canUseFeature(PlanFeature::ConnectedAccounts);
    }

    private function subscribeJsonResponse(): JsonResponse
    {
        return response()->json(['redirect' => route('subscribe')], 402);
    }

    private function subscribeRedirectResponse(): RedirectResponse
    {
        return redirect()->route('subscribe');
    }
}
