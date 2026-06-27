<?php

namespace App\Services\Stats;

use App\Features\SubscriptionExperiment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

class ExperimentFunnelCollector
{
    /**
     * Days after a variant's decision point (trial end / refund deadline) before
     * a user's outcome is settled enough to score, to let the charge clear.
     */
    private const SETTLE_BUFFER_DAYS = 3;

    /**
     * Per-variant funnel for the trial/pricing experiment. Users are attributed
     * by the variant Pennant resolved for them — the same value the runtime
     * served at checkout/paywall — so the report can't drift from what users
     * actually experienced (including any QA override or a legacy bucket that
     * predates the experiment). "Net active" is a live, non-refunded
     * subscription — an exact, heuristic-free metric that is comparable across
     * variants once each cohort clears its own decision window.
     *
     * @return array{
     *     startedAt: ?CarbonImmutable,
     *     variants: array<string, array{
     *         assigned: int,
     *         subscribed: int,
     *         trialing: int,
     *         active: int,
     *         canceled: int,
     *         pastDue: int,
     *         refunded: int,
     *         assignedMature: int,
     *         activeMature: int,
     *         netActiveRate: ?float,
     *     }>
     * }
     */
    public function collect(): array
    {
        $startedValue = config('subscriptions.experiment.started_at');
        $startedAt = $startedValue !== null ? CarbonImmutable::parse($startedValue) : null;

        $variants = [
            SubscriptionExperiment::CONTROL => $this->emptyRow(),
            SubscriptionExperiment::REDUCED_TRIAL => $this->emptyRow(),
            SubscriptionExperiment::PAY_NOW => $this->emptyRow(),
        ];

        if ($startedAt === null) {
            return ['startedAt' => null, 'variants' => $variants];
        }

        $now = CarbonImmutable::now('UTC');
        $excluded = (array) config('ai_suggestions.report.excluded_emails', []);
        $windows = $this->decisionWindows();

        User::query()
            ->where('users.created_at', '>=', $startedAt)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('email', $excluded))
            ->with(['subscriptions' => fn ($query) => $query->where('type', 'default')])
            ->select(['id', 'created_at'])
            ->chunkById(500, function ($users) use (&$variants, $windows, $now): void {
                Feature::for($users)->load([SubscriptionExperiment::class]);

                foreach ($users as $user) {
                    $variant = Feature::for($user)->value(SubscriptionExperiment::class);

                    if (! isset($variants[$variant])) {
                        continue;
                    }

                    $row = &$variants[$variant];

                    $row['assigned']++;

                    $subscription = $user->subscriptions->sortByDesc('created_at')->first();
                    $status = $subscription?->stripe_status;
                    $netActive = $status === 'active' && $subscription->refunded_at === null;

                    if ($subscription !== null) {
                        $row['subscribed']++;
                        $row['trialing'] += $status === 'trialing' ? 1 : 0;
                        $row['active'] += $status === 'active' ? 1 : 0;
                        $row['canceled'] += $status === 'canceled' ? 1 : 0;
                        $row['pastDue'] += $status === 'past_due' ? 1 : 0;
                        $row['refunded'] += $subscription->refunded_at !== null ? 1 : 0;
                    }

                    $mature = CarbonImmutable::parse($user->created_at)
                        ->addDays($windows[$variant] + self::SETTLE_BUFFER_DAYS)
                        ->lessThanOrEqualTo($now);

                    if ($mature) {
                        $row['assignedMature']++;
                        $row['activeMature'] += $netActive ? 1 : 0;
                    }

                    unset($row);
                }
            });

        foreach ($variants as $key => $row) {
            $variants[$key]['netActiveRate'] = $row['assignedMature'] > 0
                ? $row['activeMature'] / $row['assignedMature']
                : null;
        }

        return ['startedAt' => $startedAt, 'variants' => $variants];
    }

    /**
     * @return array{assigned: int, subscribed: int, trialing: int, active: int, canceled: int, pastDue: int, refunded: int, assignedMature: int, activeMature: int, netActiveRate: ?float}
     */
    private function emptyRow(): array
    {
        return [
            'assigned' => 0,
            'subscribed' => 0,
            'trialing' => 0,
            'active' => 0,
            'canceled' => 0,
            'pastDue' => 0,
            'refunded' => 0,
            'assignedMature' => 0,
            'activeMature' => 0,
            'netActiveRate' => null,
        ];
    }

    /**
     * Days from signup until each variant's outcome can be scored: the trial
     * length (the longer of the two reduced trials) or the refund window.
     *
     * @return array<string, int>
     */
    private function decisionWindows(): array
    {
        return [
            SubscriptionExperiment::CONTROL => (int) config('subscriptions.plans.monthly.trial_days', 15),
            SubscriptionExperiment::REDUCED_TRIAL => max(
                (int) config('subscriptions.experiment.reduced_trial.monthly', 3),
                (int) config('subscriptions.experiment.reduced_trial.yearly', 7),
            ),
            SubscriptionExperiment::PAY_NOW => (int) config('subscriptions.experiment.pay_now_refund_window_days', 3),
        ];
    }
}
