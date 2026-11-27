<?php

namespace App\Http\Controllers;

use App\Models\ForecastAlert;
use App\Services\Forecast\ForecastDashboardAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * "Remind me in March" on the dashboard's forecast warning: the card goes
 * away until the first day of the month before the problem one. Behind the
 * Forecast flag, like every forecast route.
 */
class ForecastAlertSnoozeController extends Controller
{
    public function store(Request $request, ForecastAlert $alert, ForecastDashboardAlert $dashboardAlert): Response
    {
        Gate::authorize('update', $alert);

        // The reminder would already be due: there is nothing to put off.
        abort_unless($dashboardAlert->snooze($alert, $request->user()), 422);

        return response()->noContent();
    }
}
