<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Http\Requests\Dashboards\ReorderDashboardsRequest;
use App\Http\Requests\Dashboards\StoreDashboardRequest;
use App\Http\Requests\Dashboards\UpdateDashboardRequest;
use App\Models\Dashboard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Creates, renames, deletes and orders the user's own dashboards. The page
 * that draws them is {@see DashboardController}.
 */
class DashboardManagementController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreDashboardRequest $request): RedirectResponse
    {
        $user = $request->user();

        $dashboard = $user->dashboards()->create([
            'name' => $request->validated('name'),
            'position' => (int) $user->dashboards()->max('position') + 1,
        ]);

        return to_route('dashboard', ['dashboard' => $dashboard->id]);
    }

    public function update(UpdateDashboardRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $dashboard->update(['name' => $request->validated('name')]);

        return back();
    }

    /**
     * Deleting the default dashboard sends the user back to the system one:
     * `users.default_dashboard_id` is nulled by its foreign key.
     */
    public function destroy(Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('delete', $dashboard);

        $dashboard->delete();

        return to_route('dashboard');
    }

    public function reorder(ReorderDashboardsRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            foreach (array_values($request->validated('ids')) as $position => $id) {
                $request->user()->dashboards()->whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }
}
