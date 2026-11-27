<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\UserActiveDay;
use Carbon\CarbonImmutable;

/**
 * The admin page's chart: sign-ups per day, and how many users were active in
 * the 30 days up to each of those days. Days are UTC, like the activity log.
 */
class SignupChart
{
    public const DAYS = 30;

    public const ACTIVE_WINDOW_DAYS = 30;

    /**
     * Oldest day first, today last; a day with nothing on it is still there.
     *
     * @return list<array{date: string, signups: int, active_users: int}>
     */
    public function points(): array
    {
        $days = $this->days();
        $signups = $this->signupsByDay($days[0]);
        $activeUsers = $this->activeUsersByDay($days);

        return array_map(fn (CarbonImmutable $day, int $index): array => [
            'date' => $day->toDateString(),
            'signups' => $signups[$day->toDateString()] ?? 0,
            'active_users' => $activeUsers[$index],
        ], $days, array_keys($days));
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function days(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return array_map(
            fn (int $offset): CarbonImmutable => $today->subDays($offset),
            range(self::DAYS - 1, 0),
        );
    }

    /**
     * @return array<string, int>
     */
    private function signupsByDay(CarbonImmutable $from): array
    {
        return User::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Distinct users per trailing window, every day in one scan of the log.
     *
     * @param  list<CarbonImmutable>  $days
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
