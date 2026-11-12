<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboards\UpdateDefaultDashboardRequest;
use Illuminate\Http\RedirectResponse;

class DefaultDashboardController extends Controller
{
    /**
     * Picks the dashboard the app opens on; null picks the system one again.
     */
    public function __invoke(UpdateDefaultDashboardRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'default_dashboard_id' => $request->validated('dashboard_id'),
        ])->save();

        return back();
    }
}
