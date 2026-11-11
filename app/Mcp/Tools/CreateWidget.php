<?php

namespace App\Mcp\Tools;

use App\Enums\WidgetOrigin;
use App\Mcp\Resources\WidgetPreview;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\User;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;

#[RendersApp(WidgetPreview::class)]
#[Description('Create a dashboard widget from a manifest and TSX. Errors come back with where and why. Call get_widget_sdk first; then place it with place_widget.')]
class CreateWidget extends WriteTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'manifest' => $schema->object()->description('{ name, size: { width, height, allowed_widths?, allowed_heights? }, data: { <key>: { endpoint, params?, paginate? } } }. See get_widget_sdk.')->required(),
            'source' => $schema->string()->description('The widget TSX: one module whose default export is the component. See get_widget_sdk for what it may use.')->required(),
        ];
    }

    protected function write(Request $request, User $user): Response|ResponseFactory
    {
        $validated = $request->validate([
            'manifest' => ['required', 'array'],
            'source' => ['required', 'string'],
        ]);

        return $this->savedWidget($user, fn () => app(WidgetDefinitionService::class)->create(
            $user,
            $validated['manifest'],
            $validated['source'],
            WidgetOrigin::Mcp,
        ));
    }
}
