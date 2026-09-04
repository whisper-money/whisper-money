<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLastActiveAt
{
    /**
     * Only write once per this many seconds to avoid a database write on every request.
     */
    private const THROTTLE_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            $lastActiveAt = $user->last_active_at;

            // A new day is always written, however recent the last write: the
            // day is what a visit streak counts, and someone who comes back a
            // minute after midnight has started a new one.
            if ($lastActiveAt === null
                || ! $this->sameSpan($user, $lastActiveAt, now(), 'day')
                || $lastActiveAt->lte(now()->subSeconds(self::THROTTLE_SECONDS))) {
                $this->carryStreak($user, $lastActiveAt, 'day', 'visit_streak', 'longest_visit_streak');
                $this->carryStreak($user, $lastActiveAt, 'week', 'visit_week_streak', 'longest_visit_week_streak');
                $user->last_active_at = now();
                $user->saveQuietly();
            }
        }

        return $response;
    }

    /**
     * Carries one run forward: one more when the span before this one counted,
     * one when the run is broken or has never started. The longest is kept
     * alongside it, because the nightly sweep is what turns a run into a medal
     * and a peak between two sweeps still happened.
     */
    private function carryStreak(User $user, ?CarbonInterface $lastActiveAt, string $unit, string $current, string $longest): void
    {
        $user->{$current} = $this->streak($user, $lastActiveAt, $unit, (int) $user->{$current});
        $user->{$longest} = max((int) $user->{$longest}, $user->{$current});
    }

    private function streak(User $user, ?CarbonInterface $lastActiveAt, string $unit, int $current): int
    {
        if ($lastActiveAt === null) {
            return 1;
        }

        if ($this->sameSpan($user, $lastActiveAt, now(), $unit)) {
            return max($current, 1);
        }

        return $this->sameSpan($user, $lastActiveAt, now()->sub($unit, 1), $unit)
            ? $current + 1
            : 1;
    }

    /**
     * Whether two instants fall in the same day or week where the reader lives,
     * which is the only place the boundary between two of them means anything.
     */
    private function sameSpan(User $user, CarbonInterface $first, CarbonInterface $second, string $unit): bool
    {
        $timezone = $user->timezone ?? config('app.timezone');
        $first = $first->copy()->setTimezone($timezone);
        $second = $second->copy()->setTimezone($timezone);

        return $unit === 'week' ? $first->isSameWeek($second) : $first->isSameDay($second);
    }
}
