<?php

namespace App\Services\Stats;

use App\Models\User;
use Carbon\CarbonImmutable;

class SubscriptionFunnelCollector
{
    private const SUBSCRIBE_WINDOW_DAYS = 30;

    private const PAID_SETTLE_BUFFER_DAYS = 5;

    private const DEFAULT_WEEKS = 26;

    private const SURGE_MULTIPLIER = 2.5;

    /**
     * Build the weekly registration -> subscription -> paid funnel.
     *
     * Every stage is measured from each user's own signup so weekly cohorts are
     * compared at the same age regardless of the calendar. "Subscribed" means a
     * default subscription was started within the window; "paid" means that
     * subscription converted past the trial (currently active, or canceled only
     * after outliving the trial — i.e. it billed at least once). By bounding both
     * stages with the same subscription-creation window, paid is always a subset
     * of subscribed and the funnel invariant registered >= subscribed >= paid holds.
     *
     * @return array{
     *     trialDays: int,
     *     weeks: list<array{
     *         week: string,
     *         weekStart: CarbonImmutable,
     *         registered: int,
     *         subscribed: int,
     *         subscribedRate: ?float,
     *         paid: int,
     *         paidRate: ?float,
     *         trialToPaidRate: ?float,
     *         surge: bool,
     *         subscribedMature: bool,
     *         paidMature: bool,
     *     }>
     * }
     */
    public function collect(?int $weeks = null): array
    {
        $weeks = max(1, $weeks ?? self::DEFAULT_WEEKS);
        $trialDays = (int) config('subscriptions.plans.monthly.trial_days', 15);

        $now = CarbonImmutable::now('UTC');
        $windowStart = $now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);

        $aggregates = $this->aggregateUsers($windowStart, $trialDays);

        $rows = [];
        $registeredCounts = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $windowStart->addWeeks($i);
            $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);
            $key = (int) $weekStart->format('oW');

            $agg = $aggregates[$key] ?? ['registered' => 0, 'subscribed' => 0, 'paid' => 0];

            $registered = $agg['registered'];
            $subscribed = $agg['subscribed'];
            $paid = $agg['paid'];

            $subscribedMature = $weekEnd->addDays(self::SUBSCRIBE_WINDOW_DAYS)->lessThanOrEqualTo($now);
            $paidMature = $weekEnd->addDays(self::SUBSCRIBE_WINDOW_DAYS + $trialDays + self::PAID_SETTLE_BUFFER_DAYS)->lessThanOrEqualTo($now);

            $registeredCounts[] = $registered;

            $rows[$i] = [
                'week' => $weekStart->format('o-\WW'),
                'weekStart' => $weekStart,
                'registered' => $registered,
                'subscribed' => $subscribed,
                'subscribedRate' => $subscribedMature && $registered > 0 ? $subscribed / $registered : null,
                'paid' => $paid,
                'paidRate' => $paidMature && $registered > 0 ? $paid / $registered : null,
                'trialToPaidRate' => $paidMature && $subscribed > 0 ? $paid / $subscribed : null,
                'surge' => false,
                'subscribedMature' => $subscribedMature,
                'paidMature' => $paidMature,
            ];
        }

        $this->flagSurges($rows, $registeredCounts);

        return [
            'trialDays' => $trialDays,
            'weeks' => array_values($rows),
        ];
    }

    /**
     * Aggregate per-user funnel flags keyed by ISO year-week of signup.
     *
     * @return array<int, array{registered: int, subscribed: int, paid: int}>
     */
    private function aggregateUsers(CarbonImmutable $windowStart, int $trialDays): array
    {
        $excluded = (array) config('ai_suggestions.report.excluded_emails', []);

        $subWindow = self::SUBSCRIBE_WINDOW_DAYS;

        $rows = User::query()
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('email', $excluded))
            ->where('users.created_at', '>=', $windowStart)
            ->selectRaw('YEARWEEK(users.created_at, 3) as yearweek')
            ->selectRaw("EXISTS(SELECT 1 FROM subscriptions s WHERE s.user_id = users.id AND s.type = 'default' AND s.created_at <= DATE_ADD(users.created_at, INTERVAL {$subWindow} DAY)) as subscribed")
            ->selectRaw("EXISTS(SELECT 1 FROM subscriptions s WHERE s.user_id = users.id AND s.type = 'default' AND s.created_at <= DATE_ADD(users.created_at, INTERVAL {$subWindow} DAY) AND (s.stripe_status = 'active' OR (s.stripe_status = 'canceled' AND s.ends_at IS NOT NULL AND TIMESTAMPDIFF(DAY, s.created_at, s.ends_at) > {$trialDays}))) as paid")
            ->toBase()
            ->get();

        $aggregates = [];

        foreach ($rows as $row) {
            $key = (int) $row->yearweek;

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = ['registered' => 0, 'subscribed' => 0, 'paid' => 0];
            }

            $aggregates[$key]['registered']++;
            $aggregates[$key]['subscribed'] += (int) $row->subscribed;
            $aggregates[$key]['paid'] += (int) $row->paid;
        }

        return $aggregates;
    }

    /**
     * Flag weeks whose signup volume is an outlier (a launch/marketing spike) so
     * a non-representative acquisition wave can't be read as an organic trend.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<int>  $registeredCounts
     */
    private function flagSurges(array &$rows, array $registeredCounts): void
    {
        $nonZero = array_values(array_filter($registeredCounts, fn (int $count): bool => $count > 0));
        $median = $this->median($nonZero);

        if ($median <= 0.0) {
            return;
        }

        foreach ($rows as $index => $row) {
            if ($row['registered'] > self::SURGE_MULTIPLIER * $median) {
                $rows[$index]['surge'] = true;
            }
        }
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
