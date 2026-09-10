<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Achievements\Awarder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Looks through everyone's history once a day and records the medals it finds.
 *
 * A sweep rather than a hook on every transaction: half the medals are about a
 * closed month or a balance at a month end, so there is nothing to react to at
 * the moment the money moves, and a nightly pass is one place to be right
 * instead of a dozen call sites to keep in step.
 *
 * A reader whose sweep throws is reported and skipped, because one broken
 * history must not cost everybody else their medals.
 */
class SweepAchievementsCommand extends Command
{
    protected $signature = 'achievements:sweep
        {--user= : Only this user, by id or email}
        {--quiet-notifications : Record without telling anyone}';

    protected $description = 'Record the achievements every user has earned';

    public function handle(): int
    {
        $swept = 0;
        $awarded = 0;

        $this->candidates()->chunkById(100, function (Collection $users) use (&$swept, &$awarded): void {
            foreach ($users as $user) {
                $swept++;
                $awarded += $this->sweep($user);
            }
        });

        $this->info("Swept {$swept} user(s), awarded {$awarded} achievement(s).");

        return self::SUCCESS;
    }

    /**
     * One reader, with an {@see Awarder} of their own.
     *
     * Resolved here rather than held for the run because of what hangs off it:
     * the rate service behind the calculators memoizes every month-end rate it
     * reads, and one kept for the whole command grows by a reader's worth of
     * rate maps on every pass until the process runs out of memory. A reader's
     * history is freed with the graph that read it, so the peak is one member
     * rather than all of them, and the cost is a dozen constructor calls
     * against the hundreds of queries a history already takes.
     */
    private function sweep(User $user): int
    {
        try {
            return $this->laravel->make(Awarder::class)
                ->sweep($user, notify: ! $this->option('quiet-notifications'))
                ->count();
        } catch (Throwable $exception) {
            report($exception);
            $this->warn("Skipped {$user->email}: {$exception->getMessage()}");

            return 0;
        }
    }

    /**
     * Everyone who finished onboarding, the seeded accounts included: the demo
     * is seeded with a year of data and no medals, and this is what fills its
     * progress screen the night after a reset. They are silenced by their
     * preferences, not by being skipped here.
     *
     * @return Builder<User>
     */
    private function candidates()
    {
        $only = $this->option('user');

        return User::query()
            ->whereNotNull('onboarded_at')
            ->when($only !== null, fn ($query) => $query->where(
                fn ($scope) => $scope->whereKey($only)->orWhere('email', $only),
            ));
    }
}
