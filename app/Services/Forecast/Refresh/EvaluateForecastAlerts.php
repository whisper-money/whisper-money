<?php

namespace App\Services\Forecast\Refresh;

use App\Models\User;
use App\Services\Forecast\ForecastAlertService;

/**
 * Brings the user's forecast alerts up to date every hour, so a problem new
 * data brings shows up, and is told about, within the hour.
 */
class EvaluateForecastAlerts implements ForecastRefreshStep
{
    public function __construct(private readonly ForecastAlertService $alerts) {}

    public function run(User $user): void
    {
        $this->alerts->refresh($user);
    }
}
