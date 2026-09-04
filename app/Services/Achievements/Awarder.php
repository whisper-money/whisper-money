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
        $recorded = $this->record($user);

        // Rewritten on every pass, not only when something was awarded: the
        // account menu reads this instead of counting on every page render, and
        // a row deleted by hand should not leave the badge wrong forever.
        $user->forceFill(['achievements_count' => $user->achievements()->count()])->saveQuietly();

        if ($recorded->isEmpty() || ! $notify) {
            return $recorded;
        }

        if ($backfill) {
            $user->notify(new AchievementsWelcome($recorded->count()));

            return $recorded;
        }

        $recorded->each(fn (Achievement $achievement) => $user->notify(new AchievementUnlocked($achievement)));

        if ($user->wantsAchievementsEmail()) {
            SendAchievementsEmailJob::dispatch($user, $recorded->pluck('id')->all());
        }

        return $recorded;
    }

    /**
     * @return Collection<int, Achievement>
     */
    private function record(User $user): Collection
    {
        $known = $user->achievements()->pluck('key')->all();
        $found = array_diff_key($this->evaluator->for($user), array_flip($known));

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
