<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\RefundSelfServe;
use App\Enums\UpsellSource;
use App\Features\SubscriptionExperiment;
use App\Models\User;
use App\Models\UserLead;
use App\Services\Discord\DiscordWebhook;
use App\Services\Subscriptions\ExperimentOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;

class SubscriptionController extends Controller
{
    public function __construct(
        private ExperimentOffer $experimentOffer,
        private DiscordWebhook $discord,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasProPlan()) {
            return redirect()->route('dashboard');
        }

        $hasBankConnections = $user->bankingConnections()->exists();
        $canUseFreePlan = ! $hasBankConnections && ! $user->hasActiveAiConsent();

        // Mark the paywall as seen so the middleware stops redirecting here.
        if ($canUseFreePlan && ! $user->hasSeenPaywall()) {
            $user->update(['paywall_seen_at' => now()]);
        }

        return Inertia::render('subscription/paywall', [
            'stats' => $this->getUserStats($user),
            'canUseFreePlan' => $canUseFreePlan,
            'canManageConnectionsForFreePlan' => $user->isOnboarded()
                && $hasBankConnections
                && $user->hasCanceledSubscription(),
            'offer' => $this->experimentOffer->offerFor($user),
        ]);
    }

    /**
     * @return array{accountsCount: int, transactionsCount: int, categoriesCount: int}
     */
    private function getUserStats(User $user): array
    {
        return [
            'accountsCount' => $user->accounts()->count(),
            'transactionsCount' => $user->transactions()->count(),
            'categoriesCount' => $user->categories()->count(),
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

        $subscriptionBuilder = $request->user()
            ->newSubscription('default', $priceId);

        if ($promotionCodeId = $this->resolveLeadPromotionCodeId($request->user(), $planKey)) {
            $subscriptionBuilder->withPromotionCode($promotionCodeId);
        } else {
            $subscriptionBuilder->allowPromotionCodes();
        }

        $trialDays = $this->experimentOffer->trialDaysFor($request->user(), $planKey);
        if ($trialDays > 0) {
            $subscriptionBuilder->trialDays($trialDays);
        }

        // Attribute revenue to the upsell point the checkout started from. The
        // value rides along as Stripe subscription metadata and is persisted
        // locally when the subscription webhook lands (see
        // PersistUpsellSourceFromStripe).
        if ($source = UpsellSource::tryFrom((string) $request->query('source', ''))) {
            $subscriptionBuilder->withMetadata(['upsell_source' => $source->value]);
        }

        return $subscriptionBuilder->checkout([
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

    /**
     * Resolve the Stripe promotion code ID assigned to the authenticated user's
     * matching UserLead for the chosen plan, if any.
     */
    private function resolveLeadPromotionCodeId(User $user, string $planKey): ?string
    {
        $lead = UserLead::query()->where('email', $user->email)->first();

        if ($lead === null) {
            return null;
        }

        $code = match ($planKey) {
            'monthly' => $lead->promo_code_monthly,
            'yearly' => $lead->promo_code_yearly,
            default => null,
        };

        if (empty($code)) {
            return null;
        }

        try {
            $promotionCodes = Cashier::stripe()->promotionCodes->all([
                'code' => $code,
                'active' => true,
                'limit' => 1,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $promotionCodes->data[0]->id ?? null;
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

        $user = $request->user();
        $subscription = $user->subscription('default');

        return Inertia::render('settings/billing', [
            'hasAiConsent' => $user->hasActiveAiConsent(),
            'refund' => [
                'canSelfRefund' => $this->experimentOffer->canSelfRefund($user),
                'deadline' => $subscription !== null && $this->experimentOffer->variantFor($user) === SubscriptionExperiment::PAY_NOW
                    ? $this->experimentOffer->refundDeadlineFor($subscription)->toIso8601String()
                    : null,
            ],
        ]);
    }

    public function refund(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->experimentOffer->canSelfRefund($user)) {
            return redirect()->route('settings.billing')
                ->withErrors(['refund' => __('This subscription is no longer eligible for a self-service refund.')]);
        }

        try {
            app(RefundSelfServe::class)->handle($user);
        } catch (\Throwable $exception) {
            $this->discord->send('', [$this->refundEmbed($user, success: false, detail: $exception->getMessage())]);

            throw $exception;
        }

        $this->discord->send('', [$this->refundEmbed($user, success: true)]);

        return redirect()->route('settings.billing')
            ->with('status', __('Your payment was refunded, your subscription was canceled, and your bank connections were disconnected.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function refundEmbed(User $user, bool $success, ?string $detail = null): array
    {
        if (! $success) {
            return [
                'title' => '🔴 Self-service refund FAILED',
                'description' => 'A pay_now refund threw — the user may have been charged without a refund. Check Stripe and Sentry now.',
                'color' => 0xED4245,
                'fields' => [
                    ['name' => 'User', 'value' => $user->email, 'inline' => false],
                    ['name' => 'Error', 'value' => substr((string) $detail, 0, 1000), 'inline' => false],
                ],
            ];
        }

        return [
            'title' => '💸 Self-service refund processed',
            'description' => 'A pay_now user refunded within the money-back window — subscription canceled and bank connections disconnected.',
            'color' => 0xFAA61A,
            'fields' => [
                ['name' => 'User', 'value' => $user->email, 'inline' => false],
            ],
        ];
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
