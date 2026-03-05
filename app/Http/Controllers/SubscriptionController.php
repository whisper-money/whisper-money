<?php

namespace App\Http\Controllers;

use App\Models\AccountBalance;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use Laravel\Pennant\Feature;

class SubscriptionController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = auth()->user();

        if ($user->hasProPlan()) {
            return redirect()->route('dashboard');
        }

        $canUseFreePlan = Feature::for($user)->active('open-banking')
            && ! $user->bankingConnections()->exists();

        // Mark the paywall as seen so the middleware stops redirecting here.
        if ($canUseFreePlan && ! $user->hasSeenPaywall()) {
            $user->update(['paywall_seen_at' => now()]);
        }

        return Inertia::render('subscription/paywall', [
            'stats' => $this->getUserStats($user),
            'canUseFreePlan' => $canUseFreePlan,
        ]);
    }

    /**
     * @return array{accountsCount: int, transactionsCount: int, categoriesCount: int, automationRulesCount: int, balancesByCurrency: array<string, int>}
     */
    private function getUserStats(User $user): array
    {
        $accounts = $user->accounts()->get();

        $balancesByCurrency = [];
        foreach ($accounts as $account) {
            $latestBalance = AccountBalance::query()
                ->where('account_id', $account->id)
                ->orderBy('balance_date', 'desc')
                ->value('balance') ?? 0;

            $currency = $account->currency_code;
            if (! isset($balancesByCurrency[$currency])) {
                $balancesByCurrency[$currency] = 0;
            }
            $balancesByCurrency[$currency] += $latestBalance;
        }

        return [
            'accountsCount' => $accounts->count(),
            'transactionsCount' => $user->transactions()->count(),
            'categoriesCount' => $user->categories()->count(),
            'automationRulesCount' => $user->automationRules()->count(),
            'balancesByCurrency' => $balancesByCurrency,
        ];
    }

    public function checkout(Request $request): Checkout
    {
        $planKey = $request->query('plan', config('subscriptions.default_plan'));
        $plan = config("subscriptions.plans.{$planKey}");

        if (! $plan || ! ($plan['stripe_lookup_key'] ?? null)) {
            abort(400, 'Invalid plan selected');
        }

        $priceId = $this->resolvePriceIdByLookupKey($plan['stripe_lookup_key']);

        return $request->user()
            ->newSubscription('default', $priceId)
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => route('subscribe.success'),
                'cancel_url' => route('subscribe.cancel'),
            ]);
    }

    /**
     * Resolve a Stripe price ID from a lookup key, with a 1-hour cache.
     */
    private function resolvePriceIdByLookupKey(string $lookupKey): string
    {
        return Cache::remember(
            "stripe_price_id:{$lookupKey}",
            now()->addHour(),
            function () use ($lookupKey): string {
                $prices = Cashier::stripe()->prices->all([
                    'lookup_keys' => [$lookupKey],
                    'limit' => 1,
                ]);

                if (empty($prices->data)) {
                    abort(500, "Stripe price not found for lookup key '{$lookupKey}'. Run `php artisan stripe:sync-prices`.");
                }

                return $prices->data[0]->id;
            }
        );
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
        if ($request->user()->isDemoAccount()) {
            return redirect()->route('settings.billing')
                ->withErrors(['demo' => 'Billing management is not available on the demo account.']);
        }

        $user = $request->user();

        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
        }

        return $user->redirectToBillingPortal(route('settings.billing'));
    }
}
