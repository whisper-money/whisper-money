<?php

namespace App\Mcp\Tools;

use App\Enums\WidgetOrigin;
use App\Http\Requests\Concerns\ValidatesDashboardName;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Models\User;
use App\Services\Dashboards\DashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create an empty custom dashboard for the user. Place widgets on it with place_widget.')]
class CreateDashboard extends WriteTool
{
    use GuardsCustomDashboards, ValidatesDashboardName;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Dashboard name, up to 60 characters, in the user\'s language.')->required(),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $validated = $request->validate(['name' => $this->dashboardNameRules()]);

        $dashboard = app(DashboardService::class)->create($user, $validated['name'], WidgetOrigin::Mcp);

        return $this->json([
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'url' => route('dashboard', ['dashboard' => $dashboard->id]),
        ]);
    }
}
