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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RendersApp(WidgetPreview::class)]
#[Description('Save a new revision of a widget: a new manifest, new TSX or both. Every dashboard it is on draws the new one. Use it to fix the last_error get_widget reports.')]
class UpdateWidget extends WriteTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'widget_id' => $schema->string()->description('Id of the widget, from list_dashboards or create_widget.')->required(),
            'manifest' => $schema->object()->description('The whole new manifest, frame included: a key left out goes back to its default. Omit it to keep the current one.'),
            'source' => $schema->string()->description('The whole new TSX. Omit it to keep the current one.'),
        ];
    }

    protected function write(Request $request, User $user): Response|ResponseFactory
    {
        $widget = $this->widgetOfUser($request, $user);

        $validated = $request->validate([
            'manifest' => ['required_without:source', 'array'],
            'source' => ['required_without:manifest', 'string'],
        ], [
            'required_without' => 'Pass the new manifest, the new source or both.',
        ]);

        return $this->savedWidget($user, fn () => app(WidgetDefinitionService::class)->update(
            $widget,
            $validated['manifest'] ?? $widget->manifest,
            $validated['source'] ?? $widget->source,
            WidgetOrigin::Mcp,
        ));
    }
}
