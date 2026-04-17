<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

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
            return false;
        }

        return ! $user->hasProPlan();
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
