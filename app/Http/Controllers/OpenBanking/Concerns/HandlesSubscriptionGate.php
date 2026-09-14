<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\PlanFeature;
use App\Enums\SignupPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

trait HandlesSubscriptionGate
{
    private function shouldBlockOpenBankingAccess(User $user, bool $allowDuringOnboarding = true): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        if ($allowDuringOnboarding && ! $user->isOnboarded()) {
            // Onboarding is open to everyone still choosing a plan, except the
            // user who came in from the free card: the wizard offers them no
            // bank connections, so the endpoint has to refuse them too.
            return SignupPlan::fromRequest($user->signup_plan) === SignupPlan::Free;
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
