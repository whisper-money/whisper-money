<?php

namespace App\Mcp\Tools;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Mcp\Tools\Concerns\GuardsCustomDashboards;
use App\Mcp\Tools\Concerns\PresentsWidgets;
use App\Models\User;
use App\Services\Widgets\WidgetRenderer;
use App\Services\Widgets\WidgetRenderUnavailable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lets the agent that wrote a widget see it: drawn in a headless browser with
 * the user's data at its dashboard cell's size ({@see WidgetRenderer}), it
 * answers with the markup and the layout problems measured there.
 */
#[IsReadOnly]
#[IsIdempotent]
#[Description('Draw one of the user\'s widgets with their data, as the dashboard does, and get its HTML, layout problems (cut-off text, overflow, empty charts) and runtime errors.')]
class RenderWidget extends McpTool
{
    use GuardsCustomDashboards, PresentsWidgets;

    private const array THEMES = ['light', 'dark'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'widget_id' => $schema->string()->description('Id of the widget, from create_widget, update_widget or list_dashboards.')->required(),
            'theme' => $schema->string()->enum(self::THEMES)->description('Color mode to draw it in. Defaults to light; check dark too.'),
            'width' => $schema->string()->enum(WidgetWidth::values())
                ->description('Width of the desktop dashboard cell to draw it in (phones are not drawn). Defaults to the manifest\'s size.width; pass another of its allowed_widths to check it there.'),
            'height' => $schema->string()->enum(WidgetHeight::values())
                ->description('Height of the cell. Defaults to the manifest\'s size.height.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $widget = $this->widgetOfUser($request, $user);

        $validated = $request->validate([
            'theme' => ['nullable', Rule::in(self::THEMES)],
            'width' => ['nullable', Rule::enum(WidgetWidth::class)],
            'height' => ['nullable', Rule::enum(WidgetHeight::class)],
        ]);

        try {
            $render = app(WidgetRenderer::class)->render(
                $user,
                $widget,
                $validated['theme'] ?? 'light',
                isset($validated['width']) ? WidgetWidth::from($validated['width']) : null,
                isset($validated['height']) ? WidgetHeight::from($validated['height']) : null,
            );
        } catch (WidgetRenderUnavailable $exception) {
            return Response::error($exception->getMessage());
        }

        $rendered = $render['status'] === 'ready';
        $errors = array_map(fn (array $error): array => [
            ...$error,
            'stack' => $this->readableStack($error['stack'] ?? null),
            'componentStack' => $this->readableStack($error['componentStack'] ?? null),
        ], $render['errors']);

        return $this->json([
            'rendered' => $rendered,
            'widget_id' => $widget->id,
            'revision' => $widget->revision,
            'status' => $render['status'],
            'size' => $render['size'],
            'diagnostics' => $render['diagnostics'],
            'errors' => $errors,
            'console' => $render['console'],
            'data_failures' => $render['data_failures'],
            'html_truncated' => $render['html_truncated'],
            'html' => $render['html'],
            'next' => $this->next($rendered, $render),
        ]);
    }

    /**
     * @param  array{diagnostics: list<mixed>, data_failures: list<mixed>, errors: list<mixed>}  $render
     */
    private function next(bool $rendered, array $render): string
    {
        if ($render['data_failures'] !== []) {
            return 'The dashboard draws an error in the widget\'s place for these calls: fix the data_failures in the manifest with update_widget, then render it again.';
        }

        if (! $rendered) {
            return 'The widget did not draw: fix the errors with update_widget, then render it again.';
        }

        if ($render['errors'] !== []) {
            return 'The widget drew but reported errors: fix them with update_widget, then render it again.';
        }

        if ($render['diagnostics'] !== []) {
            return 'Fix the diagnostics that are not intended with update_widget, then render it again. Check the html reads right too.';
        }

        return 'No layout problems found. Read the html to check texts and figures, try theme "dark" and the other allowed sizes, then place it with place_widget if it is not on a dashboard yet.';
    }
}
