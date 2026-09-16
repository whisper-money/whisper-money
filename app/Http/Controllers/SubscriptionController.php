<?php

namespace App\Http\Controllers;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Actions\Subscription\RefundSelfServe;
use App\Enums\UpsellSource;
use App\Http\Requests\ChooseFreePlanRequest;
use App\Models\BankingConnection;
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
    /** Where a checkout started mid-onboarding comes back to. */
    private const RETURN_SESSION_KEY = 'subscription.onboarding_return';

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
        $canUseFreePlan = ! $user->hasPaidFeaturesToGiveUp();

        // Mark the paywall as seen so the middleware stops redirecting here.
        if ($canUseFreePlan && ! $user->hasSeenPaywall()) {
            $user->update(['paywall_seen_at' => now()]);
        }

        return Inertia::render('subscription/paywall', [
            'stats' => $this->getUserStats($user),
            'canUseFreePlan' => $canUseFreePlan,
            'canEscapeToFreePlan' => $user->canEscapeToFreePlan(),
            'canManageConnectionsForFreePlan' => $user->isOnboarded()
                && $hasBankConnections
                && $user->hasCanceledSubscription(),
        ]);
    }

    /**
     * The confirmation in front of the free plan, as a screen of its own rather
     * than a dialog: it is the one irreversible thing the paywall offers, and
     * what it costs is specific to this user — their banks by name, their
     * movements by count. A dialog cannot hold that and stays vague instead.
     *
     * A user with nothing connected is not asked to confirm giving up nothing:
     * the paywall walks them straight out to the dashboard.
     */
    public function freePlanConfirm(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasProPlan() || ! $user->canEscapeToFreePlan()) {
            return redirect()->route('subscribe');
        }

        $connections = $user->bankingConnections()->pluck('aspsp_name')->filter()->unique()->values();

        if (! $user->hasPaidFeaturesToGiveUp()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('subscription/free-plan', [
            'banks' => $connections,
            'transactionsCount' => $user->transactions()->count(),
            'hasAiConsent' => $user->hasActiveAiConsent(),
        ]);
    }

    /**
     * Take the user down to the free plan by giving up what the paid plan
     * gates: every banking connection is disconnected and the AI consent is
     * revoked, which is the whole of `App\Enums\PlanFeature`.
     *
     * Accounts, balances and transactions already imported are kept — the same
     * terms as disconnecting by hand from Settings, only the syncing stops.
     *
     * `paywall_seen_at` is stamped here because `index()` only stamps it for
     * users who already qualified for the free plan; without it the middleware
     * would bounce this user straight back to the paywall they just left.
     */
    public function chooseFreePlan(
        ChooseFreePlanRequest $request,
        DisconnectBankingConnection $disconnectBankingConnection,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $user->bankingConnections->each(
            fn (BankingConnection $connection) => $disconnectBankingConnection->handle($connection, deleteAccounts: false),
        );

        $user->revokeAiConsent();
        $user->update(['paywall_seen_at' => now()]);

        return redirect()->route('dashboard');
    }

    /**
     * What the user already has here, which is the whole of the argument a
     * former subscriber's screen makes: the movements and rules are still
     * theirs, the accounts are the ones that stopped moving, and the date is
     * when they did.
     *
     * @return array{accountsCount: int, transactionsCount: int, categoriesCount: int, rulesCount: int, connectionsCount: int, endedAt: ?string}
     */
    private function getUserStats(User $user): array
    {
        return [
            'accountsCount' => $user->accounts()->count(),
            'transactionsCount' => $user->transactions()->count(),
            'categoriesCount' => $user->categories()->count(),
            'rulesCount' => $user->automationRules()->count(),
            'connectionsCount' => $user->bankingConnections()->count(),
            'endedAt' => $user->subscription('default')?->ends_at?->toIso8601String(),
        ];
    }

    public function checkout(Request $request): Checkout
    {
        // A seeded plan has no Stripe customer behind it, so starting a checkout
        // from one of those accounts would only reach Stripe and fail there.
        abort_if($request->user()->cannotUseStripe(), 403, 'Checkout is not available on this account.');

        $planKey = $request->query('plan', config('subscriptions.default_plan'));
        $plan = config("subscriptions.plans.{$planKey}");

        if (! $plan || ! ($plan['stripe_lookup_key'] ?? null)) {
            abort(400, 'Invalid plan selected');
        }

        $priceId = $this->resolvePriceIdByLookupKey((string) $plan['stripe_lookup_key']);

        $subscriptionBuilder = $request->user()
            ->newSubscription('default', $priceId);

        if ($promotionCodeId = $this->resolveLeadPromotionCodeId($request->user(), $planKey)) {
            $subscriptionBuilder->withPromotionCode($promotionCodeId);
        } else {
            $subscriptionBuilder->allowPromotionCodes();
        }

        $trialDays = $this->experimentOffer->trialDaysFor($request->user(), $planKey);
        if ($trialDays > 0) {
            // End of day, not `trialDays()`. Cashier turns that call into an
            // absolute `trial_end` fixed the moment this URL is built, and
            // Stripe's checkout prints the whole days still left when the page
            // renders — a few seconds later, so N days always read as N-1 and
            // "free for 15 days" arrived at a screen saying "14 days free".
            // Rounding to the end of the day leaves the remainder in [N, N+1)
            // whatever the hour, so the two numbers agree and the trial is
            // never shorter than the one we sold.
            $subscriptionBuilder->trialUntil(now()->addDays($trialDays)->endOfDay());
        }

        // Attribute revenue to the upsell point the checkout started from. The
        // value rides along as Stripe subscription metadata and is persisted
        // locally when the subscription webhook lands (see
        // PersistUpsellSourceFromStripe).
        if ($source = UpsellSource::tryFrom((string) $request->query('source', ''))) {
            $subscriptionBuilder->withMetadata(['upsell_source' => $source->value]);
            $this->rememberCheckoutIntent($request, $source);
        }

        return $subscriptionBuilder->checkout([
            'success_url' => route('subscribe.success'),
            'cancel_url' => route('subscribe.cancel'),
        ]);
    }

    /**
     * The two things a checkout started from an onboarding gate carries over
     * Stripe and back: the AI consent that gate disclosed and grouped with the
     * purchase, and the step to drop the user back on.
     *
     * The consent is recorded here rather than on the way back because here is
     * where the user gave it — they read the row and pressed the button. It is
     * inert without a plan (every AI path checks the plan first), and recording
     * it is idempotent, so an abandoned checkout leaves nothing behind but a
     * consent that only takes effect if they ever do pay.
     */
    private function rememberCheckoutIntent(Request $request, UpsellSource $source): void
    {
        if ($source->grantsAiConsent()) {
            $request->user()->recordAiConsent();
        }

        if ($return = $source->onboardingReturn()) {
            $request->session()->put(self::RETURN_SESSION_KEY, $return);
        }
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

    /**
     * Stripe's landing page, and the only place that waits for the subscription
     * to actually exist locally. A user who paid mid-onboarding is sent back
     * into it from here rather than straight from Stripe: the webhook has not
     * necessarily landed yet, and returning to the wizard a second too early
     * would show them the gate they just paid to get past.
     */
    public function success(Request $request): Response
    {
        $return = $request->session()->pull(self::RETURN_SESSION_KEY);

        return Inertia::render('subscription/success', [
            'continueUrl' => is_array($return) && ! $request->user()->isOnboarded()
                ? route('onboarding', $return)
                : null,
        ]);
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
        $canSelfRefund = $this->experimentOffer->canSelfRefund($user);

        return Inertia::render('settings/billing', [
            'hasAiConsent' => $user->hasActiveAiConsent(),
            'refund' => [
                'canSelfRefund' => $canSelfRefund,
                'deadline' => $canSelfRefund
                    ? $this->experimentOffer->refundDeadlineFor($user->subscription('default'))->toIso8601String()
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
                'description' => 'A self-service refund threw — the user may have been charged without a refund. Check Stripe and Sentry now.',
                'color' => 0xED4245,
                'fields' => [
                    ['name' => 'User', 'value' => $user->email, 'inline' => false],
                    ['name' => 'Error', 'value' => substr((string) $detail, 0, 1000), 'inline' => false],
                ],
            ];
        }

        return [
            'title' => '💸 Self-service refund processed',
            'description' => 'A user refunded within the money-back window — subscription canceled and bank connections disconnected.',
            'color' => 0xFAA61A,
            'fields' => [
                ['name' => 'User', 'value' => $user->email, 'inline' => false],
            ],
        ];
    }

    public function billingPortal(Request $request): RedirectResponse
    {
        if ($request->user()->cannotUseStripe()) {
            return redirect()->route('settings.billing')
                ->withErrors(['demo' => 'Billing management is not available on this account.']);
        }

        $user = $request->user();

        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
        }

        return $user->redirectToBillingPortal(route('settings.billing'));
    }
}
