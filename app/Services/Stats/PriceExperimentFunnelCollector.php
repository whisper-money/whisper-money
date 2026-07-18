<?php

namespace App\Services\Stats;

use App\Features\PriceExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;

/**
 * Per-variant funnel for the price experiment (control vs high). Users are
 * attributed by PriceExperiment::bucket() — the deterministic, salted crc32 split
 * that is the single source of truth for assignment — so it matches the price each
 * user was served without being perturbed by the force_variant rollout hook.
 *
 * Both arms share ONE decision window (the trial length + a settle buffer), so the
 * matured cohorts are directly comparable — unlike the trial experiment, where each
 * variant matured on its own window. The funnel is
 * assigned → activated → subscribed → net-paying.
 *
 * The decision metric is contribution margin per assigned user (MRR − connection
 * cost, ÷ assigned). Cost is the *currently-active* connections × the flat
 * per-connection estimate, matching the monthly MRR flow (revoked connections cost
 * nothing going forward). Per-user CM is accumulated as moments (sum via
 * contributionMarginCents, sum-of-squares via cmSumSq) so the report can run a
 * Welch test on CM/user across arms without retaining every value. Conversion —
 * ever-charged net of refund, time-invariant — is the guardrail metric.
 *
 * ponytail: connection cost is a blended flat estimate across all providers (only
 * Enable Banking actually bills us; API-key providers are free). It is symmetric
 * across arms, so it biases absolute CM but not the between-arm comparison. Make it
 * per-provider only if absolute margin ever needs to be trusted.
 *
 * @param  int  $costPerConnectionCents  flat estimated monthly cost per active connection
 * @return array{
 *     startedAt: ?CarbonImmutable,
 *     currency: string,
 *     revenueAvailable: bool,
 *     costPerConnectionCents: int,
 *     decisionWindowDays: int,
 *     variants: array<string, array{
 *         assigned: int, activated: int, subscribed: int,
 *         assignedMature: int, activatedMature: int, convertedMature: int, activeMature: int,
 *         payerCount: int, payerConnectionSum: int,
 *         conversionRate: ?float,
 *         mrrCents: int, costCents: int, contributionMarginCents: int,
 *         cmPerAssignedCents: ?int, cmSumSq: float, connectionsPerPayer: ?float,
 *     }>
 * }
 */
class PriceExperimentFunnelCollector
{
    /** Days after the trial ends before a user's outcome is settled enough to score. */
    private const SETTLE_BUFFER_DAYS = 3;

    public function __construct(private MonthlyEquivalentPrices $prices) {}

    public function collect(int $costPerConnectionCents = 40): array
    {
        $startedValue = config('subscriptions.price_experiment.started_at');
        $startedAt = $startedValue !== null ? CarbonImmutable::parse($startedValue) : null;
        $currency = strtoupper((string) config('cashier.currency', 'eur'));
        $window = (int) config('subscriptions.plans.monthly.trial_days', 15) + self::SETTLE_BUFFER_DAYS;

        $variants = [
            PriceExperiment::CONTROL => $this->emptyRow(),
            PriceExperiment::HIGH => $this->emptyRow(),
        ];

        if ($startedAt === null) {
            return [
                'startedAt' => null,
                'currency' => $currency,
                'revenueAvailable' => false,
                'costPerConnectionCents' => $costPerConnectionCents,
                'decisionWindowDays' => $window,
                'variants' => $variants,
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $excluded = (array) config('ai_suggestions.report.excluded_emails', []);
        $monthlyEquiv = $this->prices->map();
        $missingPrices = [];

        User::query()
            ->withTrashed()
            ->where('users.created_at', '>=', $startedAt)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('email', $excluded))
            ->with(['subscriptions' => fn ($query) => $query->where('type', 'default')])
            ->select(['id', 'created_at'])
            ->withCount([
                // "Ever connected" drives activation (cost was triggered at some point).
                'bankingConnections as connection_count' => fn ($query) => $query->withTrashed(),
                // "Currently active" drives the monthly recurring cost.
                'bankingConnections as active_connection_count',
                'aiConsents as ai_consent_count',
            ])
            ->chunkById(500, function ($users) use (&$variants, &$missingPrices, $window, $now, $monthlyEquiv, $costPerConnectionCents): void {
                foreach ($users as $user) {
                    $variant = PriceExperiment::bucket((string) $user->id);

                    if (! isset($variants[$variant])) {
                        continue;
                    }

                    $row = &$variants[$variant];

                    $row['assigned']++;

                    $everConnections = (int) ($user->connection_count ?? 0);
                    $activeConnections = (int) ($user->active_connection_count ?? 0);
                    $activated = $everConnections > 0 || (int) ($user->ai_consent_count ?? 0) > 0;

                    if ($activated) {
                        $row['activated']++;
                    }

                    /** @var Subscription|null $subscription */
                    $subscription = $user->subscriptions->sortByDesc('created_at')->first();
                    $status = $subscription?->stripe_status;
                    $netActive = $status === 'active' && $subscription->refunded_at === null;

                    // Time-invariant "ever charged, net of refund": comparable across
                    // cohorts of different ages. Excludes trial-only cancels.
                    $converted = $subscription !== null
                        && $subscription->refunded_at === null
                        && $status !== 'trialing'
                        && (
                            $subscription->trial_ends_at === null
                            || $subscription->ends_at === null
                            || $subscription->ends_at->greaterThan($subscription->trial_ends_at)
                        );

                    if ($subscription !== null) {
                        $row['subscribed']++;
                    }

                    $mature = CarbonImmutable::parse($user->created_at)
                        ->addDays($window)
                        ->lessThanOrEqualTo($now);

                    if (! $mature) {
                        unset($row);

                        continue;
                    }

                    $row['assignedMature']++;

                    if ($activated) {
                        $row['activatedMature']++;
                    }

                    if ($converted) {
                        $row['convertedMature']++;
                    }

                    $costCents = $activeConnections * $costPerConnectionCents;
                    $row['costCents'] += $costCents;

                    $revenueCents = 0;
                    if ($netActive) {
                        $row['activeMature']++;
                        $priceId = (string) $subscription->stripe_price;

                        if ($monthlyEquiv !== [] && ! isset($monthlyEquiv[$priceId])) {
                            $missingPrices[$priceId] = true;
                        }

                        $revenueCents = (int) ($monthlyEquiv[$priceId] ?? 0);
                        $row['mrrCents'] += $revenueCents;
                        $row['payerCount']++;
                        $row['payerConnectionSum'] += $activeConnections;
                    }

                    // Per-user monthly CM; cmSumSq feeds the Welch variance. The sum
                    // equals mrrCents − costCents, so it is not stored separately.
                    $cmUser = $revenueCents - $costCents;
                    $row['cmSumSq'] += $cmUser * $cmUser;

                    unset($row);
                }
            });

        if ($missingPrices !== []) {
            Log::warning('Price experiment funnel: net-active subscriptions on prices absent from the monthly-equivalent map — their MRR is undercounted as 0.', [
                'price_ids' => array_keys($missingPrices),
            ]);
        }

        foreach ($variants as $key => $row) {
            $n = $row['assignedMature'];
            $variants[$key]['conversionRate'] = $n > 0 ? (float) $row['convertedMature'] / $n : null;
            $variants[$key]['contributionMarginCents'] = $row['mrrCents'] - $row['costCents'];
            $variants[$key]['cmPerAssignedCents'] = $n > 0
                ? (int) round(($row['mrrCents'] - $row['costCents']) / $n)
                : null;
            $variants[$key]['connectionsPerPayer'] = $row['payerCount'] > 0
                ? (float) $row['payerConnectionSum'] / $row['payerCount']
                : null;
        }

        return [
            'startedAt' => $startedAt,
            'currency' => $currency,
            'revenueAvailable' => $monthlyEquiv !== [],
            'costPerConnectionCents' => $costPerConnectionCents,
            'decisionWindowDays' => $window,
            'variants' => $variants,
        ];
    }

    /**
     * @return array{assigned: int, activated: int, subscribed: int, assignedMature: int, activatedMature: int, convertedMature: int, activeMature: int, payerCount: int, payerConnectionSum: int, conversionRate: ?float, mrrCents: int, costCents: int, contributionMarginCents: int, cmPerAssignedCents: ?int, cmSumSq: float, connectionsPerPayer: ?float}
     */
    private function emptyRow(): array
    {
        return [
            'assigned' => 0,
            'activated' => 0,
            'subscribed' => 0,
            'assignedMature' => 0,
            'activatedMature' => 0,
            'convertedMature' => 0,
            'activeMature' => 0,
            'payerCount' => 0,
            'payerConnectionSum' => 0,
            'conversionRate' => null,
            'mrrCents' => 0,
            'costCents' => 0,
            'contributionMarginCents' => 0,
            'cmPerAssignedCents' => null,
            'cmSumSq' => 0.0,
            'connectionsPerPayer' => null,
        ];
    }
}
