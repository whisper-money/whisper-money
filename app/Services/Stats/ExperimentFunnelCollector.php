<?php

namespace App\Services\Stats;

use App\Features\SubscriptionExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;

class ExperimentFunnelCollector
{
    /**
     * Days after a variant's decision point (trial end / refund deadline) before
     * a user's outcome is settled enough to score, to let the charge clear.
     */
    private const SETTLE_BUFFER_DAYS = 3;

    /**
     * Per-variant funnel for the subscription experiment. Users are attributed
     * by SubscriptionExperiment::bucket() — the deterministic crc32 split that is
     * the single source of truth for assignment — over the in-window signups the
     * query selects, so it matches the variant each user was served without being
     * perturbed by the force_variant rollout hook (which pins every user to the
     * winner once decided) or by Pennant store drift. "Net active" is a live,
     * non-refunded subscription — an exact, heuristic-free metric that is
     * comparable across variants once each cohort clears its own decision window.
     *
     * The funnel is assigned → activated → carded (subscribed) → net-paying:
     * "activated" = the user connected a bank or enabled AI, i.e. triggered the
     * paid infrastructure that costs us money, whether or not they ever paid. The
     * gap activated → carded (completed Checkout with a card) is where a user
     * connects a bank and walks away without paying — the exact leak the flat
     * per-connection cost estimate quantifies.
     *
     * Revenue is the monthly-recurring run-rate (MRR) of the mature, net-active
     * subscriptions, with yearly plans normalised to a monthly equivalent, plus
     * ARPU = MRR ÷ assigned (mature). Cost is a flat estimate: connections of the
     * mature cohort × `$costPerConnectionCents`; "wasted" cost is the same for
     * mature users who did not convert (money burned). Contribution margin =
     * MRR − cost, the decision metric once every variant has mature volume. This
     * is run-rate, not realised cash, so an upfront yearly payment is credited
     * no differently from a monthly one.
     *
     * @param  int  $costPerConnectionCents  flat estimated cost per bank connection
     * @return array{
     *     startedAt: ?CarbonImmutable,
     *     currency: string,
     *     revenueAvailable: bool,
     *     costPerConnectionCents: int,
     *     variants: array<string, array{
     *         assigned: int,
     *         activated: int,
     *         subscribed: int,
     *         trialing: int,
     *         trialingCanceling: int,
     *         active: int,
     *         canceled: int,
     *         pastDue: int,
     *         refunded: int,
     *         assignedMature: int,
     *         activatedMature: int,
     *         convertedMature: int,
     *         activeMature: int,
     *         conversionRate: ?float,
     *         netActiveRate: ?float,
     *         activationToPaidRate: ?float,
     *         mrrCents: int,
     *         arpuCents: ?int,
     *         costCents: int,
     *         wastedCostCents: int,
     *         contributionMarginCents: int,
     *     }>
     * }
     */
    public function collect(int $costPerConnectionCents = 40): array
    {
        $startedValue = config('subscriptions.experiment.started_at');
        $startedAt = $startedValue !== null ? CarbonImmutable::parse($startedValue) : null;
        $currency = strtoupper((string) config('cashier.currency', 'eur'));

        $variants = array_fill_keys(SubscriptionExperiment::variants(), $this->emptyRow());

        if ($startedAt === null || $variants === []) {
            return ['startedAt' => null, 'currency' => $currency, 'revenueAvailable' => false, 'costPerConnectionCents' => $costPerConnectionCents, 'variants' => $variants];
        }

        $now = CarbonImmutable::now('UTC');
        $excluded = (array) config('ai_suggestions.report.excluded_emails', []);
        $windows = $this->decisionWindows();
        $monthlyEquiv = $this->monthlyEquivByPriceId();
        $missingPrices = [];

        User::query()
            // Soft-deleted accounts still count: they were assigned a variant and
            // their bank connections incurred real cost, and deleting the account
            // is itself an experiment outcome (the strongest "connect and leave").
            ->withTrashed()
            ->where('users.created_at', '>=', $startedAt)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('email', $excluded))
            ->with(['subscriptions' => fn ($query) => $query->where('type', 'default')])
            ->select(['id', 'created_at'])
            ->withCount([
                // Cost is incurred by every connection ever opened, so count
                // soft-deleted (revoked) ones too — they still cost us money.
                'bankingConnections as connection_count' => fn ($query) => $query->withTrashed(),
                'aiConsents as ai_consent_count',
            ])
            ->chunkById(500, function ($users) use (&$variants, &$missingPrices, $windows, $now, $monthlyEquiv, $costPerConnectionCents): void {
                foreach ($users as $user) {
                    // Attribute by the deterministic bucket (the single source of
                    // truth in SubscriptionExperiment), not the resolved Pennant
                    // value: the latter is short-circuited by the force_variant
                    // rollout hook, which would collapse every user onto one
                    // variant once a winner is pinned. Every queried user is
                    // in-window, so bucket() equals the variant they were served,
                    // and reading it avoids writing Pennant rows as a side effect.
                    $variant = SubscriptionExperiment::bucket((string) $user->id);

                    if (! isset($variants[$variant])) {
                        continue;
                    }

                    $mature = CarbonImmutable::parse($user->created_at)
                        ->addDays($windows[$variant] + self::SETTLE_BUFFER_DAYS)
                        ->lessThanOrEqualTo($now);

                    $this->tallyUser($variants[$variant], $user, $mature, $costPerConnectionCents, $monthlyEquiv, $missingPrices);
                }
            });

        if ($missingPrices !== []) {
            Log::warning('Experiment funnel: net-active subscriptions on prices absent from the monthly-equivalent map — their MRR is undercounted as 0.', [
                'price_ids' => array_keys($missingPrices),
            ]);
        }

        return [
            'startedAt' => $startedAt,
            'currency' => $currency,
            'revenueAvailable' => $monthlyEquiv !== [],
            'costPerConnectionCents' => $costPerConnectionCents,
            'variants' => $this->withDerivedRates($variants),
        ];
    }

    /**
     * Add one user to their variant's row: the lifetime counters always, and the
     * mature-cohort ones only once the user's decision window has closed.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $monthlyEquiv
     * @param  array<string, true>  $missingPrices
     */
    private function tallyUser(array &$row, User $user, bool $mature, int $costPerConnectionCents, array $monthlyEquiv, array &$missingPrices): void
    {
        $connections = (int) ($user->connection_count ?? 0);
        $activated = $connections > 0 || (int) ($user->ai_consent_count ?? 0) > 0;

        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions->sortByDesc('created_at')->first();

        $this->tallyLifetime($row, $subscription, $activated);

        if ($mature) {
            $this->tallyMature($row, $subscription, $activated, $connections * $costPerConnectionCents, $monthlyEquiv, $missingPrices);
        }
    }

    /**
     * Lifetime counters: everyone assigned, whatever their cohort's age.
     *
     * @param  array<string, mixed>  $row
     */
    private function tallyLifetime(array &$row, ?Subscription $subscription, bool $activated): void
    {
        $row['assigned']++;
        $row['activated'] += $activated ? 1 : 0;

        if ($subscription === null) {
            return;
        }

        $status = $subscription->stripe_status;

        $row['subscribed']++;
        $row['trialing'] += $status === 'trialing' ? 1 : 0;
        $row['trialingCanceling'] += ($status === 'trialing' && $subscription->ends_at !== null) ? 1 : 0;
        $row['active'] += $status === 'active' ? 1 : 0;
        $row['canceled'] += $status === 'canceled' ? 1 : 0;
        $row['pastDue'] += $status === 'past_due' ? 1 : 0;
        $row['refunded'] += $subscription->refunded_at !== null ? 1 : 0;
    }

    /**
     * Mature-cohort counters: conversions, revenue and the connection cost that
     * the contribution margin is measured against.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $monthlyEquiv
     * @param  array<string, true>  $missingPrices
     */
    private function tallyMature(array &$row, ?Subscription $subscription, bool $activated, int $connectionCostCents, array $monthlyEquiv, array &$missingPrices): void
    {
        $row['assignedMature']++;
        $row['costCents'] += $connectionCostCents;
        $row['activatedMature'] += $activated ? 1 : 0;
        $row['convertedMature'] += $this->wasEverCharged($subscription) ? 1 : 0;

        $netActive = $subscription?->stripe_status === 'active' && $subscription->refunded_at === null;

        if ($netActive) {
            $row['activeMature']++;
            $priceId = (string) $subscription->stripe_price;

            if ($monthlyEquiv !== [] && ! isset($monthlyEquiv[$priceId])) {
                $missingPrices[$priceId] = true;
            }

            $row['mrrCents'] += (int) ($monthlyEquiv[$priceId] ?? 0);

            return;
        }

        // Burn = connections of matured users who never earned net revenue:
        // connected a bank but never carded, or paid and got refunded. A user who
        // paid and later churned (canceled, not refunded) did convert, so their
        // connection cost is not burn.
        if ($subscription === null || $subscription->refunded_at !== null) {
            $row['wastedCostCents'] += $connectionCostCents;
        }
    }

    /**
     * Time-invariant conversion: the user was ever charged and not refunded —
     * currently active, or churned after the trial. Unlike a live net-active
     * snapshot it does not shrink as an older cohort has more time to cancel, so
     * it is comparable across variants that matured at different times. Excludes
     * trial-only cancels (ended on/before the trial → never charged).
     */
    private function wasEverCharged(?Subscription $subscription): bool
    {
        if ($subscription === null || $subscription->refunded_at !== null || $subscription->stripe_status === 'trialing') {
            return false;
        }

        return $subscription->trial_ends_at === null
            || $subscription->ends_at === null
            || $subscription->ends_at->greaterThan($subscription->trial_ends_at);
    }

    /**
     * The ratios and the contribution margin, derived from the tallied counters.
     *
     * @param  array<string, array<string, mixed>>  $variants
     * @return array<string, array<string, mixed>>
     */
    private function withDerivedRates(array $variants): array
    {
        foreach ($variants as $key => $row) {
            $variants[$key]['conversionRate'] = $row['assignedMature'] > 0
                ? (float) $row['convertedMature'] / $row['assignedMature']
                : null;
            $variants[$key]['netActiveRate'] = $row['assignedMature'] > 0
                ? (float) $row['activeMature'] / $row['assignedMature']
                : null;
            $variants[$key]['activationToPaidRate'] = $row['activatedMature'] > 0
                ? (float) $row['activeMature'] / $row['activatedMature']
                : null;
            $variants[$key]['arpuCents'] = $row['assignedMature'] > 0
                ? (int) round($row['mrrCents'] / $row['assignedMature'])
                : null;
            $variants[$key]['contributionMarginCents'] = $row['mrrCents'] - $row['costCents'];
        }

        return $variants;
    }

    /**
     * @return array{assigned: int, activated: int, subscribed: int, trialing: int, trialingCanceling: int, active: int, canceled: int, pastDue: int, refunded: int, assignedMature: int, activatedMature: int, convertedMature: int, activeMature: int, conversionRate: ?float, netActiveRate: ?float, activationToPaidRate: ?float, mrrCents: int, arpuCents: ?int, costCents: int, wastedCostCents: int, contributionMarginCents: int}
     */
    private function emptyRow(): array
    {
        return [
            'assigned' => 0,
            'activated' => 0,
            'subscribed' => 0,
            'trialing' => 0,
            'trialingCanceling' => 0,
            'active' => 0,
            'canceled' => 0,
            'pastDue' => 0,
            'refunded' => 0,
            'assignedMature' => 0,
            'activatedMature' => 0,
            'convertedMature' => 0,
            'activeMature' => 0,
            'conversionRate' => null,
            'netActiveRate' => null,
            'activationToPaidRate' => null,
            'mrrCents' => 0,
            'arpuCents' => null,
            'costCents' => 0,
            'wastedCostCents' => 0,
            'contributionMarginCents' => 0,
        ];
    }

    /**
     * Monthly-equivalent amount (in cents) for each plan price id, from Stripe.
     * Yearly prices are divided by 12. Fetched by product so that archived,
     * rotated price ids (Stripe mints a new id and transfers the lookup key on
     * any amount change) still resolve — otherwise subscriptions on an old id
     * would silently contribute 0 to MRR. Falls back to the current lookup keys
     * when no product is configured. Foreign-currency and one-off prices are
     * skipped. Cached for an hour; returns [] (revenue unavailable) if Stripe
     * can't be reached, without caching the failure.
     *
     * @return array<string, int>
     */
    private function monthlyEquivByPriceId(): array
    {
        $key = 'experiment_funnel_monthly_equiv';

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $productId = config('subscriptions.products.pro');
        $lookups = array_values(array_filter([
            config('subscriptions.plans.monthly.stripe_lookup_key'),
            config('subscriptions.plans.yearly.stripe_lookup_key'),
        ]));

        if ($productId === null && $lookups === []) {
            return [];
        }

        $params = $productId !== null
            ? ['product' => $productId, 'limit' => 100]
            : ['lookup_keys' => $lookups, 'limit' => 10];

        try {
            $prices = Cashier::stripe()->prices->all($params);
        } catch (\Throwable) {
            return [];
        }

        $map = $this->toMonthlyEquiv($prices->data);

        if ($map !== []) {
            Cache::put($key, $map, now()->addHour());
        }

        return $map;
    }

    /**
     * Monthly-equivalent cents per price id: recurring prices in the Cashier
     * currency only, yearly ones divided by 12.
     *
     * @param  iterable<object>  $prices
     * @return array<string, int>
     */
    private function toMonthlyEquiv(iterable $prices): array
    {
        $currency = strtolower((string) config('cashier.currency', 'eur'));
        $map = [];

        foreach ($prices as $price) {
            if ($price->recurring === null || strtolower((string) ($price->currency ?? $currency)) !== $currency) {
                continue;
            }

            $amount = (int) ($price->unit_amount ?? 0);
            $map[$price->id] = ($price->recurring->interval ?? 'month') === 'year'
                ? (int) round($amount / 12)
                : $amount;
        }

        return $map;
    }

    /**
     * Days from signup until each variant's outcome can be scored: its longest
     * trial, or the refund window for a variant that charges upfront.
     *
     * @return array<string, int>
     */
    private function decisionWindows(): array
    {
        $refundWindow = (int) config('subscriptions.experiment.refund_window_days', 3);
        $windows = [];

        foreach (SubscriptionExperiment::variants() as $variant) {
            $trial = max(array_map(
                fn (string $planKey): int => (int) config(
                    "subscriptions.experiment.variants.{$variant}.trial_days.{$planKey}",
                    config("subscriptions.plans.{$planKey}.trial_days", 0),
                ),
                ['monthly', 'yearly'],
            ));

            $windows[$variant] = $trial > 0 ? $trial : $refundWindow;
        }

        return $windows;
    }
}
