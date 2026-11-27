<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\UserActiveDay;
use App\Support\PeriodSelection;
use Carbon\CarbonImmutable;

/**
 * The admin page's chart: sign-ups per bucket of a calendar period, and how
 * many users were active in the 30 days up to the end of each bucket, next to
 * the same figures for the period before it. Days are UTC, like the activity
 * log. A month or a quarter is drawn by day, a year by ISO week (Monday to
 * Sunday, clipped to the year).
 *
 * @phpstan-type Bucket array{start: CarbonImmutable, end: CarbonImmutable}
 * @phpstan-type Figures array{signups: int, active_users: int}
 * @phpstan-type Side array{start: string, end: string, signups: int|null, active_users: int|null}
 */
class SignupChart
{
    private const ACTIVE_WINDOW_DAYS = 30;

    /**
     * Every bucket of the period: the ones after today are there with null
     * figures, so the axis spans the whole period. Each carries the bucket at
     * the same index of the previous period, null past that period's end.
     *
     * @return array{period_type: string, period: string, previous_period: string, bucket: 'day'|'week', points: list<array{start: string, end: string, signups: int|null, active_users: int|null, previous: Side|null}>, summary: array{signups: int, active_users: int, previous_signups: int, previous_active_users: int, in_progress: bool}}
     */
    public function for(PeriodSelection $selection): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $selection->start($today);
        $end = $selection->end($start);
        $previousStart = $selection->previous($start);
        $weekly = $selection->type === 'year';

        $current = $this->buckets($start, $end, $weekly);
        $previous = $this->buckets($previousStart, $start->subDay(), $weekly);
        $inProgress = $start->lte($today) && $end->gt($today);
        $totals = $this->totalSpans($start, $end, $previousStart, $today, $inProgress);

        $figures = $this->figures([
            ...$this->keyed('previous', $previous),
            ...$this->keyed('current', $current),
            ...$totals,
        ], $today);

        return [
            'period_type' => $selection->type,
            'period' => $selection->param($start),
            'previous_period' => $selection->param($previousStart),
            'bucket' => $weekly ? 'week' : 'day',
            'points' => array_map(fn (array $bucket, int $index): array => [
                ...$this->side($bucket, $figures["current.{$index}"] ?? null),
                'previous' => isset($previous[$index]) ? $this->side($previous[$index], $figures["previous.{$index}"] ?? null) : null,
            ], $current, array_keys($current)),
            'summary' => [
                'signups' => $figures['total']['signups'] ?? 0,
                'active_users' => $figures['total']['active_users'] ?? 0,
                'previous_signups' => $figures['previous_total']['signups'] ?? 0,
                'previous_active_users' => $figures['previous_total']['active_users'] ?? 0,
                'in_progress' => $inProgress,
            ],
        ];
    }

    /**
     * The stretches the totals cover: the period up to today, and the one
     * before it. While the period is in progress the previous one is cut at
     * the same number of days, so a half-gone month is not set against a
     * whole one, nor a week of three days against one of seven.
     *
     * @return array<string, Bucket>
     */
    private function totalSpans(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart, CarbonImmutable $today, bool $inProgress): array
    {
        $previousEnd = $start->subDay();

        return [
            'total' => ['start' => $start, 'end' => $end],
            'previous_total' => [
                'start' => $previousStart,
                'end' => $inProgress ? $previousStart->addDays((int) $start->diffInDays($today))->min($previousEnd) : $previousEnd,
            ],
        ];
    }

    /**
     * @param  list<Bucket>  $buckets
     * @return array<string, Bucket>
     */
    private function keyed(string $prefix, array $buckets): array
    {
        return array_combine(
            array_map(fn (int $index): string => "{$prefix}.{$index}", array_keys($buckets)),
            $buckets,
        );
    }

    /**
     * @param  Bucket  $bucket
     * @param  Figures|null  $figures  Null for a bucket after today.
     * @return Side
     */
    private function side(array $bucket, ?array $figures): array
    {
        return [
            'start' => $bucket['start']->toDateString(),
            'end' => $bucket['end']->toDateString(),
            'signups' => $figures['signups'] ?? null,
            'active_users' => $figures['active_users'] ?? null,
        ];
    }

    /**
     * Each bucket's sign-ups up to its end, and the users active in the 30
     * days up to its end, both capped at today. A bucket that starts after
     * today has no figures and is left out.
     *
     * @param  array<string, Bucket>  $buckets
     * @return array<string, Figures>
     */
    private function figures(array $buckets, CarbonImmutable $today): array
    {
        $buckets = array_map(
            fn (array $bucket): array => ['start' => $bucket['start'], 'end' => $bucket['end']->min($today)],
            array_filter($buckets, fn (array $bucket): bool => $bucket['start']->lte($today)),
        );

        if ($buckets === []) {
            return [];
        }

        $ends = array_map(fn (array $bucket): CarbonImmutable => $bucket['end'], $buckets);
        $signups = $this->signupsByDay(min(array_column($buckets, 'start')), max($ends));
        $activeUsers = $this->activeUsersByDay($ends);

        $figures = [];

        foreach ($buckets as $key => $bucket) {
            $figures[$key] = [
                'signups' => $this->sumBetween($signups, $bucket['start']->toDateString(), $bucket['end']->toDateString()),
                'active_users' => $activeUsers[$key],
            ];
        }

        return $figures;
    }

    /**
     * @return list<Bucket>
     */
    private function buckets(CarbonImmutable $start, CarbonImmutable $end, bool $weekly): array
    {
        $buckets = [];

        for ($day = $start; $day->lte($end); $day = $bucketEnd->addDay()) {
            $bucketEnd = $weekly ? $day->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()->min($end) : $day;
            $buckets[] = ['start' => $day, 'end' => $bucketEnd];
        }

        return $buckets;
    }

    /**
     * @param  array<string, int>  $countsByDay
     */
    private function sumBetween(array $countsByDay, string $from, string $to): int
    {
        return array_sum(array_filter(
            $countsByDay,
            fn (string $day): bool => $day >= $from && $day <= $to,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @return array<string, int>
     */
    private function signupsByDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return User::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to->addDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn (mixed $total, mixed $day): array => [(string) $day => (int) $total])
            ->all();
    }

    /**
     * Distinct users per trailing window, every day in one scan of the log.
     *
     * @param  array<string, CarbonImmutable>  $days
     * @return array<string, int>
     */
    private function activeUsersByDay(array $days): array
    {
        $keys = array_keys($days);
        $columns = [];
        $bindings = [];

        foreach (array_values($days) as $index => $day) {
            $columns[] = "COUNT(DISTINCT CASE WHEN date BETWEEN ? AND ? THEN user_id END) as d{$index}";
            $bindings[] = $this->windowStart($day)->toDateString();
            $bindings[] = $day->toDateString();
        }

        $row = UserActiveDay::query()
            ->whereBetween('date', [$this->windowStart(min($days))->toDateString(), max($days)->toDateString()])
            ->selectRaw(implode(', ', $columns), $bindings)
            ->toBase()
            ->first();

        return array_combine($keys, array_map(fn (int $index): int => (int) ($row->{"d{$index}"} ?? 0), array_keys($keys)));
    }

    private function windowStart(CarbonImmutable $day): CarbonImmutable
    {
        return $day->subDays(self::ACTIVE_WINDOW_DAYS - 1);
    }
}
