<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\BankingProvider;
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
     *
     * The provider decides which feature is checked: a latam subscriber keeps
     * the direct integrations but not the bank aggregator.
     */
    private function shouldBlockOpenBankingAccess(User $user, BankingProvider $provider): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        return ! $user->canUseFeature($provider->planFeature());
    }

    private function subscribeJsonResponse(User $user): JsonResponse
    {
        return response()->json(['redirect' => $this->planGateUrl($user)], 402);
    }

    private function subscribeRedirectResponse(User $user): RedirectResponse
    {
        $redirect = redirect()->to($this->planGateUrl($user));

        return $user->hasProPlan()
            ? $redirect->with('error', __('Bank connections are available on the standard plan.'))
            : $redirect;
    }

    /**
     * Where a refused user can get the plan they lack. Someone already paying
     * (a latam subscriber reaching for a bank) changes plan from billing: the
     * paywall sends every subscriber to the dashboard.
     */
    private function planGateUrl(User $user): string
    {
        return $user->hasProPlan() ? route('settings.billing') : route('subscribe');
    }
}
