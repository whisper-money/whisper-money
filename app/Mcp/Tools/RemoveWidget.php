<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Take a widget off a dashboard by its placement_id. A widget of the user\'s is kept and can be placed again; a built-in one can be put back from the app.')]
class RemoveWidget extends WriteTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dashboard_id' => $schema->string()->description('Id of the dashboard, from list_dashboards.')->required(),
            'placement_id' => $schema->string()->description('The placement_id list_dashboards gives for the widget on that dashboard.')->required(),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $dashboard = $this->dashboardOfUser($request, $user);
        $id = $request->string('placement_id')->toString();

        $placement = $dashboard->widgets()->whereKey($id)->first()
            ?? throw ValidationException::withMessages(['placement_id' => "No widget placed with id {$id} on dashboard {$dashboard->id}. Call list_dashboards to see valid ids."]);

        $placement->delete();

        return $this->json(['removed' => true, 'placement_id' => $placement->id, 'dashboard_id' => $dashboard->id]);
    }
}
