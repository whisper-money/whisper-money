<?php

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboards\ReorderDashboardWidgetsRequest;
use App\Http\Requests\Dashboards\StoreDashboardWidgetRequest;
use App\Http\Requests\Dashboards\UpdateDashboardWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Places system widgets on a dashboard, removes them, orders them and sizes
 * them. The `{widget}` binding is scoped to its `{dashboard}`, so a widget
 * from another dashboard is a 404.
 */
class DashboardWidgetController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreDashboardWidgetRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $dashboard->widgets()->create([
            'system_widget' => $request->systemWidget(),
            ...$request->size(),
            'position' => (int) $dashboard->widgets()->max('position') + 1,
        ]);

        return back();
    }

    public function update(UpdateDashboardWidgetRequest $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $widget->update($request->requestedSize());

        return back();
    }

    public function destroy(Request $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('update', $dashboard);

        $widget->delete();

        return back();
    }

    public function reorder(ReorderDashboardWidgetsRequest $request, Dashboard $dashboard): RedirectResponse
    {
        foreach (array_values($request->validated('ids')) as $position => $id) {
            $dashboard->widgets()->whereKey($id)->update(['position' => $position]);
        }

        return back();
    }
}
