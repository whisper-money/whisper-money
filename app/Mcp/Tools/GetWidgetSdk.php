<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Models\User;
use App\Services\Widgets\WidgetSdkReference;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The reference for writing a dashboard widget: manifest, sizes, data endpoints and params, SDK, allowed imports, globals, tags, attributes and CSS classes. Read it before create_widget.')]
class GetWidgetSdk extends McpTool
{
    use GuardsCustomDashboards;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        return $this->json(app(WidgetSdkReference::class)->toArray());
    }
}
