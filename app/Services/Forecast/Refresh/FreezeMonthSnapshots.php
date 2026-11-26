<?php

namespace App\Services\Forecast\Refresh;

use App\Models\User;
use App\Services\Forecast\ForecastSnapshotService;

/**
 * Freezes the plan of the month the user is in, once, as soon as it opens on
 * their clock.
 */
class FreezeMonthSnapshots implements ForecastRefreshStep
{
    public function __construct(private readonly ForecastSnapshotService $snapshots) {}

    public function run(User $user): void
    {
        $this->snapshots->freezeCurrentMonth($user);
    }
}
