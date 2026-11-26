<?php

namespace App\Services\Forecast\Refresh;

use App\Models\User;

/**
 * One piece of the background upkeep `forecast:refresh` runs for each user
 * with the `Forecast` flag. A step must be safe to repeat: the command runs
 * every hour and can be retried.
 */
interface ForecastRefreshStep
{
    public function run(User $user): void;
}
