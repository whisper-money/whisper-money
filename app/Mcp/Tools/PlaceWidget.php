<?php

namespace App\Mcp\Tools;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\User;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Place one of the user\'s widgets at the end of a dashboard, at its default size or one its manifest allows. The same widget can be on several dashboards.')]
class PlaceWidget extends WriteTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dashboard_id' => $schema->string()->description('Id of the dashboard, from list_dashboards or create_dashboard.')->required(),
            'widget_id' => $schema->string()->description('Id of the widget, from list_dashboards or create_widget.')->required(),
            'width' => $schema->string()->enum(array_column(WidgetWidth::cases(), 'value'))->description('Fraction of the row. Pass it with height, or neither for the default size.'),
            'height' => $schema->string()->enum(array_column(WidgetHeight::cases(), 'value'))->description('m, or l (twice as tall). Pass it with width.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $dashboard = $this->dashboardOfUser($request, $user);
        $widget = $this->widgetOfUser($request, $user);

        $validated = $request->validate([
            'width' => ['required_with:height', Rule::enum(WidgetWidth::class)],
            'height' => ['required_with:width', Rule::enum(WidgetHeight::class)],
        ]);

        $size = isset($validated['width'])
            ? ['width' => WidgetWidth::from($validated['width']), 'height' => WidgetHeight::from($validated['height'])]
            : null;

        try {
            $placement = app(WidgetDefinitionService::class)->place($dashboard, $widget, $size);
        } catch (InvalidWidget $exception) {
            return $this->invalidWidget($exception);
        }

        return $this->json([
            'placed' => true,
            ...$this->presentPlacement($placement),
            'url' => route('dashboard', ['dashboard' => $dashboard->id]),
        ]);
    }
}
