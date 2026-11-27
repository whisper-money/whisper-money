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
 * @phpstan-type Point array{start: string, end: string, signups: int|null, active_users: int|null, previous_start: string|null, previous_end: string|null, previous_signups: int|null, previous_active_users: int|null}
 */
class SignupChart
{
    private const ACTIVE_WINDOW_DAYS = 30;

    /**
     * Every bucket of the period, today's last among those drawn: the ones
     * after today are there with null figures, so the axis spans the whole
     * period. The previous period's buckets sit at the same index.
     *
     * @return array{period_type: string, period: string, previous_period: string, bucket: 'day'|'week', points: list<Point>, summary: array{signups: int, active_users: int, previous_signups: int, previous_active_users: int}}
     */
    public function for(PeriodSelection $selection): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $selection->start($today);
        $previousStart = $selection->previous($start);
        $weekly = $selection->type === 'year';

        $current = $this->buckets($start, $selection->end($start), $weekly);
        $previous = $this->buckets($previousStart, $start->subDay(), $weekly);

        $drawnPrevious = $this->untilToday($previous, $today);
        $drawn = $this->untilToday($current, $today);
        $figures = $this->figures([...$drawnPrevious, ...$drawn], $today);
        $previousFigures = array_slice($figures, 0, count($drawnPrevious));
        $currentFigures = array_slice($figures, count($drawnPrevious));

        return [
            'period_type' => $selection->type,
            'period' => $selection->param($start),
            'previous_period' => $selection->param($previousStart),
            'bucket' => $weekly ? 'week' : 'day',
            'points' => array_map(fn (array $bucket, int $index): array => [
                'start' => $bucket['start']->toDateString(),
                'end' => $bucket['end']->toDateString(),
                'signups' => $currentFigures[$index]['signups'] ?? null,
                'active_users' => $currentFigures[$index]['active_users'] ?? null,
                'previous_start' => isset($previous[$index]) ? $previous[$index]['start']->toDateString() : null,
                'previous_end' => isset($previous[$index]) ? $previous[$index]['end']->toDateString() : null,
                'previous_signups' => $previousFigures[$index]['signups'] ?? null,
                'previous_active_users' => $previousFigures[$index]['active_users'] ?? null,
            ], $current, array_keys($current)),
            'summary' => $this->summary($currentFigures, $previousFigures, count($drawn) < count($current)),
        ];
    }

    /**
     * The buckets that have started by today, the only ones with figures.
     *
     * @param  list<Bucket>  $buckets
     * @return list<Bucket>
     */
    private function untilToday(array $buckets, CarbonImmutable $today): array
    {
        return array_values(array_filter($buckets, fn (array $bucket): bool => $bucket['start']->lte($today)));
    }

    /**
     * Each bucket's sign-ups and active users, in the order given.
     *
     * @param  list<Bucket>  $buckets
     * @return list<array{signups: int, active_users: int}>
     */
    private function figures(array $buckets, CarbonImmutable $today): array
    {
        if ($buckets === []) {
            return [];
        }

        $signups = $this->signupsByDay($buckets[0]['start'], end($buckets)['end']);
        $activeUsers = $this->activeUsersByDay(array_map(
            fn (array $bucket): CarbonImmutable => $bucket['end']->min($today),
            $buckets,
        ));

        return array_map(fn (array $bucket, int $index): array => [
            'signups' => $this->sumBetween($signups, $bucket['start']->toDateString(), $bucket['end']->toDateString()),
            'active_users' => $activeUsers[$index],
        ], $buckets, array_keys($buckets));
    }

    /**
     * The period's totals: sign-ups in it, and the active users of its last
     * drawn bucket. While the period is in progress the previous one is cut
     * at the same bucket, so a half-gone month is not set against a whole one.
     *
     * @param  list<array{signups: int, active_users: int}>  $current
     * @param  list<array{signups: int, active_users: int}>  $previous
     * @return array{signups: int, active_users: int, previous_signups: int, previous_active_users: int}
     */
    private function summary(array $current, array $previous, bool $inProgress): array
    {
        $comparable = $inProgress ? array_slice($previous, 0, count($current)) : $previous;

        return [
            'signups' => array_sum(array_column($current, 'signups')),
            'active_users' => $current === [] ? 0 : end($current)['active_users'],
            'previous_signups' => array_sum(array_column($comparable, 'signups')),
            'previous_active_users' => $comparable === [] ? 0 : end($comparable)['active_users'],
        ];
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
     * @param  list<CarbonImmutable>  $days  Ascending.
     * @return list<int>
     */
    private function activeUsersByDay(array $days): array
    {
        $columns = [];
        $bindings = [];

        foreach ($days as $index => $day) {
            $columns[] = "COUNT(DISTINCT CASE WHEN date BETWEEN ? AND ? THEN user_id END) as d{$index}";
            $bindings[] = $this->windowStart($day)->toDateString();
            $bindings[] = $day->toDateString();
        }

        $row = UserActiveDay::query()
            ->whereBetween('date', [$this->windowStart($days[0])->toDateString(), end($days)->toDateString()])
            ->selectRaw(implode(', ', $columns), $bindings)
            ->toBase()
            ->first();

        return array_map(fn (int $index): int => (int) ($row->{"d{$index}"} ?? 0), array_keys($days));
    }

    private function windowStart(CarbonImmutable $day): CarbonImmutable
    {
        return $day->subDays(self::ACTIVE_WINDOW_DAYS - 1);
    }
}
