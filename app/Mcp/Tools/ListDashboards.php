<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s custom dashboards with the widgets placed on each, and every widget the user has. Use the ids with place_widget, remove_widget and get_widget.')]
class ListDashboards extends McpTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        $dashboards = $user->dashboards()->orderBy('position')
            ->with(['widgets' => fn ($query) => $query->orderBy('position')])
            ->get()
            ->map(fn (Dashboard $dashboard): array => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'is_default' => $dashboard->id === $user->default_dashboard_id,
                'widgets' => $dashboard->widgets->map(fn (DashboardWidget $placement): array => $this->presentPlacement($placement))->all(),
            ]);

        $widgets = $user->widgetDefinitions()->orderBy('name')->get()
            ->map(fn (WidgetDefinition $widget): array => $this->presentWidget($widget));

        return $this->json([
            'default_dashboard_id' => $user->default_dashboard_id,
            'dashboards' => $dashboards,
            'widgets' => $widgets,
        ]);
    }
}
