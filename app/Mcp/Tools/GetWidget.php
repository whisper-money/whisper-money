<?php

namespace App\Mcp\Tools;

use App\Mcp\Resources\WidgetPreview;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RendersApp(WidgetPreview::class)]
#[Description('Get one of the user\'s widgets: manifest, TSX, revisions, where it is placed and the last runtime error the dashboard caught, with the revision it came from.')]
class GetWidget extends McpTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'widget_id' => $schema->string()->description('Id of the widget, from list_dashboards.')->required(),
        ];
    }

    protected function respond(Request $request, User $user): ResponseFactory
    {
        return $this->widgetResponse($user, $this->widgetOfUser($request, $user));
    }
}
