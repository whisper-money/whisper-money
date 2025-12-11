<?php

namespace App\Http\Controllers;

use App\Models\AccountBalance;
use App\Models\User;
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

        return Inertia::render('subscription/paywall', [
            'stats' => $this->getUserStats($user),
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

        if (! $plan || ! $plan['stripe_price_id']) {
            abort(400, 'Invalid plan selected');
        }

        $priceId = $plan['stripe_price_id'];

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
