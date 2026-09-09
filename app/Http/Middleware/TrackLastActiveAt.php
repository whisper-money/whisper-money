<?php

namespace App\Http\Middleware;

use App\Features\Achievements;
use App\Models\User;
use App\Services\Achievements\Awarder;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackLastActiveAt
{
    /**
     * Only write once per this many seconds to avoid a database write on every request.
     */
    private const THROTTLE_SECONDS = 300;

    public function __construct(private Awarder $awarder) {}

    /**
     * Handle an incoming request.
     *
     * Before the response, not after it. The shared Inertia props are closures
     * the renderer calls further down the stack, so a run carried forward here
     * is the run the page is drawn with — the reader sees the day they are on,
     * and the medal it just earned, on the request that earned it rather than
     * on the next one.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->track($user);
        }

        return $next($request);
    }

    private function track(User $user): void
    {
        $lastActiveAt = $user->last_active_at;

        // A new day is always written, however recent the last write: the day
        // is what a visit streak counts, and someone who comes back a minute
        // after midnight has started a new one.
        if ($lastActiveAt !== null
            && $this->sameSpan($user, $lastActiveAt, now(), 'day')
            && $lastActiveAt->gt(now()->subSeconds(self::THROTTLE_SECONDS))) {
            return;
        }

        $before = $this->runs($user);

        $this->carryStreak($user, $lastActiveAt, 'day', 'visit_streak', 'longest_visit_streak');
        $this->carryStreak($user, $lastActiveAt, 'week', 'visit_week_streak', 'longest_visit_week_streak');
        $user->last_active_at = now();
        $user->saveQuietly();

        if ($this->runs($user) !== $before) {
            $this->award($user);
        }
    }

    /**
     * Both runs as they stand, which is the whole of what a visit medal is
     * judged on: unchanged means there is nothing new to award.
     *
     * @return array{int, int}
     */
    private function runs(User $user): array
    {
        return [(int) $user->longest_visit_streak, (int) $user->longest_visit_week_streak];
    }

    /**
     * The medal a run just reached, settled now instead of overnight.
     *
     * Cheap enough to sit on a request — one read of the reader's own medal
     * keys against a catalog held in memory, and at most one insert — and only
     * reached on the first request of a new day, which is the only time a run
     * moves. A reader still onboarding is left to the sweep, the way every
     * other medal is.
     */
    private function award(User $user): void
    {
        if ($user->onboarded_at === null || ! Feature::for($user)->active(Achievements::class)) {
            return;
        }

        try {
            $this->awarder->awardVisitRuns($user);
        } catch (Throwable $exception) {
            // Never at the cost of the page the reader actually asked for: the
            // sweep will find the same medal tonight.
            report($exception);
        }
    }

    /**
     * Carries one run forward: one more when the span before this one counted,
     * one when the run is broken or has never started. The longest is kept
     * alongside it, because that is what a medal is judged on: a run that
     * peaked and broke before anyone looked still happened.
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
