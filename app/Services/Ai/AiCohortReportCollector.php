<?php

namespace App\Services\Ai;

use App\Models\AiConsent;
use App\Models\User;
use Carbon\CarbonImmutable;

class AiCohortReportCollector
{
    private const ELIGIBILITY_WINDOW_DAYS = 7;

    private const RETENTION_DAYS = 14;

    private const TRIAL_DAYS = 14;

    private const PAID_DAYS = 30;

    private const DEFAULT_WEEKS = 16;

    private const SURGE_MULTIPLIER = 2.5;

    /**
     * Build the weekly eligible-cohort time series for the AI suggestions feature.
     *
     * Eligibility, metric horizons and maturity windows are all measured from
     * each user's own signup, so every weekly cohort is compared at the same
     * age regardless of the calendar.
     *
     * @return array{
     *     releaseAt: ?CarbonImmutable,
     *     releaseWeek: ?string,
     *     weeks: list<array{
     *         week: string,
     *         weekStart: CarbonImmutable,
     *         eligible: int,
     *         retained: int,
     *         retainedRate: ?float,
     *         trial: int,
     *         trialRate: ?float,
     *         paid: int,
     *         paidRate: ?float,
     *         aiAccepted: int,
     *         aiAcceptedRate: ?float,
     *         phase: string,
     *         surge: bool,
     *         retentionMature: bool,
     *         paidMature: bool,
     *     }>
     * }
     */
    public function collect(?int $weeks = null): array
    {
        $weeks = max(1, $weeks ?? (int) config('ai_suggestions.report.weeks', self::DEFAULT_WEEKS));

        $now = CarbonImmutable::now('UTC');
        $windowStart = $now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($weeks - 1);

        $releaseValue = AiConsent::query()->min('accepted_at');
        $releaseAt = $releaseValue !== null ? CarbonImmutable::parse($releaseValue) : null;
        $releaseWeekStart = $releaseAt?->startOfWeek(CarbonImmutable::MONDAY);

        $aggregates = $this->aggregateEligibleUsers($windowStart);

        $rows = [];
        $eligibleCounts = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $windowStart->addWeeks($i);
            $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);
            $key = (int) $weekStart->format('oW');

            $agg = $aggregates[$key] ?? [
                'eligible' => 0,
                'retained' => 0,
                'trial' => 0,
                'paid' => 0,
                'aiAccepted' => 0,
            ];

            $eligible = $agg['eligible'];
            $retentionMature = $weekEnd->addDays(self::RETENTION_DAYS)->lessThanOrEqualTo($now);
            $paidMature = $weekEnd->addDays(self::PAID_DAYS)->lessThanOrEqualTo($now);

            $eligibleCounts[] = $eligible;

            $rows[$i] = [
                'week' => $weekStart->format('o-\WW'),
                'weekStart' => $weekStart,
                'eligible' => $eligible,
                'retained' => $agg['retained'],
                'retainedRate' => $retentionMature && $eligible > 0 ? $agg['retained'] / $eligible : null,
                'trial' => $agg['trial'],
                'trialRate' => $retentionMature && $eligible > 0 ? $agg['trial'] / $eligible : null,
                'paid' => $agg['paid'],
                'paidRate' => $paidMature && $eligible > 0 ? $agg['paid'] / $eligible : null,
                'aiAccepted' => $agg['aiAccepted'],
                'aiAcceptedRate' => $eligible > 0 ? $agg['aiAccepted'] / $eligible : null,
                'phase' => $this->phase($weekStart, $releaseWeekStart),
                'surge' => false,
                'retentionMature' => $retentionMature,
                'paidMature' => $paidMature,
            ];
        }

        $this->flagSurges($rows, $eligibleCounts);

        return [
            'releaseAt' => $releaseAt,
            'releaseWeek' => $releaseWeekStart?->format('o-\WW'),
            'weeks' => array_values($rows),
        ];
    }

    /**
     * Aggregate per-user metric flags for eligible users, keyed by ISO year-week.
     *
     * @return array<int, array{eligible: int, retained: int, trial: int, paid: int, aiAccepted: int}>
     */
    private function aggregateEligibleUsers(CarbonImmutable $windowStart): array
    {
        $threshold = (int) config('ai_suggestions.eligibility_min_transactions', 50);
        $excluded = (array) config('ai_suggestions.report.excluded_emails', []);

        $rows = User::query()
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('email', $excluded))
            ->where('users.created_at', '>=', $windowStart)
            ->whereHas('transactions', function ($query): void {
                $query->whereRaw(
                    'transactions.created_at <= DATE_ADD(users.created_at, INTERVAL '.self::ELIGIBILITY_WINDOW_DAYS.' DAY)',
                );
            }, '>=', $threshold)
            ->selectRaw('YEARWEEK(users.created_at, 3) as yearweek')
            ->selectRaw('(users.last_active_at IS NOT NULL AND users.last_active_at >= DATE_ADD(users.created_at, INTERVAL '.self::RETENTION_DAYS.' DAY)) as retained')
            ->selectRaw('EXISTS(SELECT 1 FROM subscriptions s WHERE s.user_id = users.id AND s.created_at <= DATE_ADD(users.created_at, INTERVAL '.self::TRIAL_DAYS.' DAY)) as has_trial')
            ->selectRaw("EXISTS(SELECT 1 FROM subscriptions s WHERE s.user_id = users.id AND s.stripe_status = 'active' AND s.created_at <= DATE_ADD(users.created_at, INTERVAL ".self::PAID_DAYS.' DAY)) as has_paid')
            ->selectRaw('EXISTS(SELECT 1 FROM ai_consents c WHERE c.user_id = users.id AND c.accepted_at IS NOT NULL) as ai_accepted')
            ->toBase()
            ->get();

        $aggregates = [];

        foreach ($rows as $row) {
            $key = (int) $row->yearweek;

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = ['eligible' => 0, 'retained' => 0, 'trial' => 0, 'paid' => 0, 'aiAccepted' => 0];
            }

            $aggregates[$key]['eligible']++;
            $aggregates[$key]['retained'] += (int) $row->retained;
            $aggregates[$key]['trial'] += (int) $row->has_trial;
            $aggregates[$key]['paid'] += (int) $row->has_paid;
            $aggregates[$key]['aiAccepted'] += (int) $row->ai_accepted;
        }

        return $aggregates;
    }

    private function phase(CarbonImmutable $weekStart, ?CarbonImmutable $releaseWeekStart): string
    {
        if ($releaseWeekStart === null) {
            return 'pre';
        }

        return $weekStart->lessThan($releaseWeekStart) ? 'pre' : 'post';
    }

    /**
     * Flag weeks whose eligible volume is an outlier (e.g. a launch/marketing
     * spike) so a non-representative acquisition wave can't be read as an
     * organic trend.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<int>  $eligibleCounts
     */
    private function flagSurges(array &$rows, array $eligibleCounts): void
    {
        $nonZero = array_values(array_filter($eligibleCounts, fn (int $count): bool => $count > 0));
        $median = $this->median($nonZero);

        if ($median <= 0.0) {
            return;
        }

        foreach ($rows as $index => $row) {
            if ($row['eligible'] > self::SURGE_MULTIPLIER * $median) {
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
