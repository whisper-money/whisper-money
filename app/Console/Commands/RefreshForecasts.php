<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Forecast\ForecastAudience;
use App\Services\Forecast\Refresh\EvaluateForecastAlerts;
use App\Services\Forecast\Refresh\ForecastRefreshStep;
use App\Services\Forecast\Refresh\FreezeMonthSnapshots;
use App\Services\Forecast\Refresh\RematchRecentTransactions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The forecast's background upkeep, for users with the `Forecast` flag only:
 * everyone else is never read. Each user runs the hourly steps every run,
 * and the weekly ones once a week, in the run where it is Monday 06:00 on
 * their clock. Later phases add their work (suggestions) as steps to these
 * lists.
 */
class RefreshForecasts extends Command
{
    /**
     * @var list<class-string<ForecastRefreshStep>>
     */
    private const HOURLY_STEPS = [
        FreezeMonthSnapshots::class,
        RematchRecentTransactions::class,
        EvaluateForecastAlerts::class,
    ];

    /**
     * @var list<class-string<ForecastRefreshStep>>
     */
    private const WEEKLY_STEPS = [];

    private const WEEKLY_HOUR = 6;

    protected $signature = 'forecast:refresh';

    protected $description = 'Freeze the forecast of each month as it opens, match recent transactions to forecast occurrences and raise forecast alerts, for users with the Forecast flag';

    public function handle(ForecastAudience $audience): int
    {
        $refreshed = 0;

        $failed = 0;

        foreach ($audience->users() as $user) {
            $succeeded = true;

            foreach ($this->stepsFor($user) as $step) {
                // One user or step that fails must not hold back the rest.
                try {
                    app($step)->run($user);
                } catch (Throwable $exception) {
                    $succeeded = false;
                    Log::warning('forecast:refresh step failed', ['user_id' => $user->id, 'step' => $step]);
                    report($exception);
                }
            }

            $succeeded ? $refreshed++ : $failed++;
        }

        $this->info("Refreshed the forecast of {$refreshed} users ({$failed} with a failed step)");

        return self::SUCCESS;
    }

    /**
     * @return list<class-string<ForecastRefreshStep>>
     */
    private function stepsFor(User $user): array
    {
        $now = $user->localNow();
        $weekly = $now->isMonday() && $now->hour === self::WEEKLY_HOUR ? self::WEEKLY_STEPS : [];

        return [...self::HOURLY_STEPS, ...$weekly];
    }
}
