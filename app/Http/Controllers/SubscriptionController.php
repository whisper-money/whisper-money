<?php

namespace App\Http\Controllers;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Actions\Subscription\CancelSubscriptionNow;
use App\Actions\Subscription\SwapLatamToStandard;
use App\Enums\PaywallGate;
use App\Enums\UpsellSource;
use App\Http\Requests\ChooseFreePlanRequest;
use App\Http\Requests\StoreFreePlanSurveyResponseRequest;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserLead;
use App\Services\Subscriptions\StripePriceIds;
use App\Services\Subscriptions\SubscribedPlan;
use App\Services\Subscriptions\UserSetupStats;
use App\Support\PriceTiers;
use App\Support\SubscriptionPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    /** Where a checkout started mid-onboarding comes back to. */
    private const RETURN_SESSION_KEY = 'subscription.onboarding_return';

    public function __construct(
        private SubscribedPlan $subscribedPlan,
        private StripePriceIds $priceIds,
        private SwapLatamToStandard $swapLatamToStandard,
        private UserSetupStats $setupStats,
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
            'canManageConnectionsForFreePlan' => $this->isFormerSubscriberWithBanks($user, $hasBankConnections),
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
            // Which paywall screen sent the user here, for the analytics of the
            // survey asked on the way out. Only the two gates with something to
            // give up do. The answer itself records `User::paywallGate()`.
            'gate' => $this->isFormerSubscriberWithBanks($user, $connections->isNotEmpty())
                ? PaywallGate::FormerSubscriber
                : PaywallGate::Hard,
        ]);
    }

    /**
     * Whether the paywall shows this user the screen for someone who paid and
     * stopped, rather than the one for a user who never paid.
     */
    private function isFormerSubscriberWithBanks(User $user, bool $hasBankConnections): bool
    {
        return $user->isOnboarded()
            && $hasBankConnections
            && $user->hasCanceledSubscription();
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
     * Store why the user is staying on the free plan.
     *
     * No redirect: the survey sits in front of the way out of the paywall, and
     * the page that opened it decides where the user goes next — the dashboard,
     * or the disconnect the free-plan screen was already confirmed for. A
     * response that fails to save must never hold that up.
     */
    public function storeFreePlanSurvey(StoreFreePlanSurveyResponseRequest $request): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->freePlanSurveyResponses()->create($request->answer());

        return response()->noContent();
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
            ...$this->setupStats->for($user),
            'endedAt' => $user->subscription('default')?->ends_at?->toIso8601String(),
        ];
    }

    public function checkout(Request $request): Checkout
    {
        // A seeded plan has no Stripe customer behind it, so starting a checkout
        // from one of those accounts would only reach Stripe and fail there.
        abort_if($request->user()->cannotUseStripe(), 403, 'Checkout is not available on this account.');

        $planKey = $request->query('plan', config('subscriptions.default_plan'));
        $isLatam = $this->checksOutAtLatam($request);
        $plan = ($isLatam ? $request->user()->offeredPlans() : config('subscriptions.plans', []))[$planKey] ?? null;

        if (! $plan || ! ($plan['stripe_lookup_key'] ?? null)) {
            abort(400, 'Invalid plan selected');
        }

        $priceId = $this->priceIds->forLookupKey((string) $plan['stripe_lookup_key']);

        $subscriptionBuilder = $request->user()
            ->newSubscription('default', $priceId);

        // A lead's code was issued against the standard price, and Stripe
        // refuses to open a checkout with a code that does not apply to the
        // price being bought — the latam price only takes one typed by hand.
        $promotionCodeId = $isLatam ? null : $this->resolveLeadPromotionCodeId($request->user(), $planKey);

        if ($promotionCodeId !== null) {
            $subscriptionBuilder->withPromotionCode($promotionCodeId);
        } else {
            $subscriptionBuilder->allowPromotionCodes();
        }

        $trialDays = (int) ($plan['trial_days'] ?? 0);
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

        // A return left by an earlier checkout the user walked away from must
        // not send this one, if it is canceled, back into the wizard.
        $request->session()->forget(self::RETURN_SESSION_KEY);

        // Attribute revenue to the upsell point the checkout started from, to
        // the plan the picker had preselected and to the regional price, as
        // Stripe subscription metadata. The first two are also persisted
        // locally when the subscription webhook lands (see
        // PersistCheckoutAttributionFromStripe).
        $metadata = $this->checkoutMetadata($request, $isLatam);

        if ($metadata !== []) {
            $subscriptionBuilder->withMetadata($metadata);
        }

        return $subscriptionBuilder->checkout([
            'success_url' => route('subscribe.success'),
            'cancel_url' => route('subscribe.cancel'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function checkoutMetadata(Request $request, bool $isLatam): array
    {
        $metadata = [];

        if ($source = UpsellSource::tryFrom((string) $request->query('source', ''))) {
            $metadata['upsell_source'] = $source->value;
            $this->rememberCheckoutIntent($request, $source);
        }

        if ($preselectedPlan = $this->preselectedPlan($request)) {
            $metadata['preselected_plan'] = $preselectedPlan;
        }

        if ($isLatam) {
            $metadata['price_tier'] = PriceTiers::LATAM;
        }

        return $metadata;
    }

    /**
     * The plan the picker had selected when it was shown, which is what the
     * default-plan switch moves (an experiment arm can override it on its own
     * screen, so the config alone does not say what the user saw). Only an
     * existing plan key is kept: anything else is dropped rather than refusing
     * the checkout, because a bad measurement must never cost a sale.
     */
    private function preselectedPlan(Request $request): ?string
    {
        $planKey = (string) $request->query('preselected_plan', '');

        return SubscriptionPlans::isKnown($planKey) ? $planKey : null;
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
     * Whether this checkout is for the regional latam price. The browser only
     * asks for it (`?tier=latam`); the user's own offered tier decides, so a
     * tier nobody offered them is refused rather than quietly swapped for the
     * standard price they did not pick. Anyone may buy the standard price,
     * latam users included — it is how they get bank connections.
     */
    private function checksOutAtLatam(Request $request): bool
    {
        if ($request->query('tier') !== PriceTiers::LATAM) {
            return false;
        }

        abort_unless($request->user()->offeredPriceTier() === PriceTiers::LATAM, 403, 'This price is not available on this account.');

        return true;
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
        return Inertia::render('subscription/success', [
            'continueUrl' => $this->pullOnboardingReturnUrl($request),
        ]);
    }

    /**
     * Where Stripe sends a user who backs out of the checkout. One who started
     * it mid-onboarding goes back to the step they left, not to `/subscribe`:
     * the paywall and its free-plan survey are not something to show to a user
     * who has not finished setting up yet, and would record a survey answer
     * from someone who was only looking at the plans.
     */
    public function cancel(Request $request): RedirectResponse
    {
        return redirect()->to($this->pullOnboardingReturnUrl($request) ?? route('subscribe'));
    }

    /**
     * The onboarding step a checkout started from, consumed once the user is
     * back from Stripe. Null for a checkout started anywhere else, and for a
     * user who has finished onboarding in the meantime.
     */
    private function pullOnboardingReturnUrl(Request $request): ?string
    {
        $return = $request->session()->pull(self::RETURN_SESSION_KEY);

        return is_array($return) && ! $request->user()->isOnboarded()
            ? route('onboarding', $return)
            : null;
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
            'hasUnpaidSubscription' => $user->unpaidSubscription() !== null,
            'subscription' => $subscription === null ? null : [
                'onTrial' => $subscription->onTrial(),
                'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
                'onGracePeriod' => $subscription->onGracePeriod(),
                // When a scheduled cancellation takes effect: the trial end for
                // a trial, the end of the paid period otherwise.
                'endsAt' => $subscription->onGracePeriod() ? $subscription->ends_at?->toIso8601String() : null,
                'plan' => $this->subscribedPlan->for($user, $subscription),
                // The standard price a latam subscriber can move up to, for the
                // button that does it; null for everyone else.
                'standardUpgrade' => $this->standardUpgradeFor($user, $subscription),
            ],
        ]);
    }

    /**
     * @return array{price: float, billing_period: string}|null
     */
    private function standardUpgradeFor(User $user, Subscription $subscription): ?array
    {
        $standardPlan = $this->swapLatamToStandard->standardPlanFor($user, $subscription);

        return $standardPlan === null ? null : [
            'price' => (float) $standardPlan['price'],
            'billing_period' => (string) $standardPlan['billing_period'],
        ];
    }

    /**
     * Move a latam subscriber onto the standard price of the same period. The
     * request names no price: the only move there is goes to standard, and
     * anyone not on latam is refused.
     */
    public function swapToStandard(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->subscription('default');
        $standardPlan = $this->swapLatamToStandard->standardPlanFor($user, $subscription);

        abort_if($standardPlan === null, 403, 'This subscription cannot be moved to the standard plan.');

        try {
            $this->swapLatamToStandard->handle($subscription, $standardPlan);
        } catch (IncompletePayment $exception) {
            // The card needs confirming (3DS). Cashier's own page does it and
            // comes back here.
            return redirect()->route('cashier.payment', [
                $exception->payment->asStripePaymentIntent()->id,
                'redirect' => route('settings.billing'),
            ]);
        }

        return redirect()->route('settings.billing')
            ->with('success', __('You are on the standard plan now. You can connect your banks.'));
    }

    /**
     * Cancel, right now, a subscription whose charge failed. Stripe's portal is
     * no reliable way out for it (an `incomplete` one is not even listed), and
     * someone who only wants the charges to stop should not have to delete the
     * account to get there.
     */
    public function cancelUnpaid(Request $request, CancelSubscriptionNow $cancelSubscriptionNow): RedirectResponse
    {
        $user = $request->user();
        $subscription = $user->unpaidSubscription();

        if ($subscription === null) {
            return redirect()->route('settings.billing');
        }

        try {
            $cancelSubscriptionNow->handle($user, $subscription);
        } catch (ApiErrorException $exception) {
            report($exception);

            return redirect()->route('settings.billing')
                ->withErrors(['subscription' => __('We could not cancel your subscription. Please try again in a few minutes.')]);
        }

        return redirect()->route('settings.billing');
    }

    /**
     * Take back a cancellation the user scheduled: the plan carries on, and a
     * trial still converts on the day it was always going to. Only the user's
     * own `default` subscription is ever touched, and only while Cashier has it
     * on its grace period (cancelled, not yet ended) — Stripe refuses to resume
     * anything else, and so do we, before asking it.
     */
    public function resume(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->subscription('default');

        if ($subscription === null || ! $subscription->onGracePeriod() || $user->cannotUseStripe()) {
            return redirect()->route('settings.billing')
                ->withErrors(['resume' => __('There is no cancellation to undo on this plan.')]);
        }

        try {
            $subscription->resume();
        } catch (ApiErrorException $exception) {
            report($exception);

            return redirect()->route('settings.billing')
                ->withErrors(['resume' => __('We could not keep your plan. Please try again in a few minutes.')]);
        }

        return redirect()->route('settings.billing')
            ->with('success', __('Your plan is back on. Nothing else changes.'));
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
