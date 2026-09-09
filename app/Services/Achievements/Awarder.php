<?php

namespace App\Services\Achievements;

use App\Jobs\Drip\SendAchievementsEmailJob;
use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementsWelcome;
use App\Notifications\AchievementUnlocked;
use Illuminate\Support\Collection;

/**
 * Records the medals a sweep found, and says so.
 *
 * How loudly depends on whether the reader has been swept before. The first
 * sweep reads their whole history and can unlock twenty medals at once: those
 * are recorded silently and one welcome row says how many. Every sweep after
 * that is about something that just happened, so each medal gets its own row in
 * the bell and the day's batch gets one email between them.
 *
 * {@see awardVisitRuns()} is the one thing that does not wait for the sweep,
 * and it announces itself through the same two methods so a medal reads the
 * same whether the night found it or the visit did.
 */
class Awarder
{
    public function __construct(private Evaluator $evaluator) {}

    /**
     * @return Collection<int, Achievement> the medals recorded by this sweep
     */
    public function sweep(User $user, bool $notify = true): Collection
    {
        $backfill = ! $user->achievements()->exists();
        $recorded = $this->record($user, $this->evaluator->for($user));

        // Rewritten on every pass, not only when something was awarded: the
        // account menu reads this instead of counting on every page render, and
        // a row deleted by hand should not leave the badge wrong forever.
        $this->tally($user);

        if ($recorded->isEmpty() || ! $notify) {
            return $recorded;
        }

        if ($backfill) {
            $user->notify(new AchievementsWelcome($recorded->count()));

            return $recorded;
        }

        return $this->announce($user, $recorded);
    }

    /**
     * The visit medals a run has just earned, recorded on the request that
     * moved it rather than on the sweep that night.
     *
     * A run is the one thing the app knows the whole truth about the instant it
     * changes — two columns, no history to read — so making the reader wait
     * until the small hours to be told they kept a week going was a delay with
     * nothing behind it. Everything else still waits for the sweep, which is
     * where a closed month or a balance at a month end can actually be judged.
     *
     * Skipped for a reader with no medals at all, which is a reader the sweep
     * has never been through: their first pass reads a whole life and says so
     * with a single welcome row, and taking that first medal here would turn it
     * into a pile of individual ones instead.
     *
     * @return Collection<int, Achievement> the medals recorded by this visit
     */
    public function awardVisitRuns(User $user): Collection
    {
        if (! $user->achievements()->exists()) {
            return collect();
        }

        $recorded = $this->record($user, $this->evaluator->visitRuns($user));

        if ($recorded->isEmpty()) {
            return $recorded;
        }

        $this->tally($user);

        return $this->announce($user, $recorded);
    }

    /**
     * One row in the bell per medal, and one email for the batch.
     *
     * @param  Collection<int, Achievement>  $recorded
     * @return Collection<int, Achievement>
     */
    private function announce(User $user, Collection $recorded): Collection
    {
        $recorded->each(fn (Achievement $achievement) => $user->notify(new AchievementUnlocked($achievement)));

        if ($user->wantsAchievementsEmail()) {
            SendAchievementsEmailJob::dispatch($user, $recorded->pluck('id')->all());
        }

        return $recorded;
    }

    private function tally(User $user): void
    {
        $user->forceFill(['achievements_count' => $user->achievements()->count()])->saveQuietly();
    }

    /**
     * @param  array<string, Unlock>  $found
     * @return Collection<int, Achievement>
     */
    private function record(User $user, array $found): Collection
    {
        $known = $user->achievements()->pluck('key')->all();
        $found = array_diff_key($found, array_flip($known));

        if ($found === []) {
            return collect();
        }

        $spaceId = $user->activeSpace()->id;

        return collect($found)
            // Oldest first, so a backfill reads as a life in order and the row
            // ids sort the same way the screen does.
            ->sortBy(fn (Unlock $unlock): string => $unlock->month)
            ->map(fn (Unlock $unlock, string $key): Achievement => $user->achievements()->create([
                'space_id' => $spaceId,
                'key' => $key,
                ...$unlock->attributes(),
            ]))
            ->values();
    }
}
