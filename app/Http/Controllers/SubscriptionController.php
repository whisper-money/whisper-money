<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Checkout;

class SubscriptionController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = auth()->user();

        if ($user->hasProPlan()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('subscription/paywall');
    }

    public function checkout(Request $request): Checkout
    {
        $priceId = config('subscriptions.prices.pro_monthly');

        return $request->user()
            ->newSubscription('default', $priceId)
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => route('subscribe.success'),
                'cancel_url' => route('subscribe.cancel'),
            ]);
    }

    public function success(): Response
    {
        return Inertia::render('subscription/success');
    }

    public function cancel(): RedirectResponse
    {
        return redirect()->route('subscribe');
    }

    public function billing(Request $request): Response|RedirectResponse
    {
        if (! config('subscriptions.enabled')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('settings/billing');
    }

    public function billingPortal(Request $request): RedirectResponse
    {
        return $request->user()->redirectToBillingPortal(route('settings.billing'));
    }
}
