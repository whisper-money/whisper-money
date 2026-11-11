<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetPreviewPayload;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * The dashboard and widget shapes the dashboard tools return, and how they
 * find the caller's own rows. Dashboards and widgets belong to one user:
 * another user's id is refused like one that does not exist.
 */
trait PresentsWidgets
{
    protected function dashboardOfUser(Request $request, User $user, string $key = 'dashboard_id'): Dashboard
    {
        $id = $request->string($key)->toString();

        return $user->dashboards()->whereKey($id)->first()
            ?? throw ValidationException::withMessages([$key => "No dashboard with id {$id}. Call list_dashboards to see valid ids."]);
    }

    protected function widgetOfUser(Request $request, User $user, string $key = 'widget_id'): WidgetDefinition
    {
        $id = $request->string($key)->toString();

        return $user->widgetDefinitions()->whereKey($id)->first()
            ?? throw ValidationException::withMessages([$key => "No widget with id {$id}. Call list_dashboards to see the user's widgets."]);
    }

    /**
     * Why a widget was not saved or placed, every reason in the shape the
     * agent fixes it from: where (`manifest.size.width`, `source:12:5`), what
     * is wrong, and sometimes a hint.
     */
    protected function invalidWidget(InvalidWidget $exception, string $outcome = 'saved'): Response
    {
        return Response::error((string) json_encode([
            $outcome => false,
            'errors' => $exception->errors,
            'next' => 'Fix every error listed and call the tool again. get_widget_sdk lists what widgets may use.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Save a widget through $save and answer with it and its preview, or with
     * every reason it was not saved.
     *
     * @param  Closure(): WidgetDefinition  $save
     */
    protected function savedWidget(User $user, Closure $save): Response|ResponseFactory
    {
        try {
            $widget = $save();
        } catch (InvalidWidget $exception) {
            return $this->invalidWidget($exception);
        }

        return $this->widgetResponse($user, $widget, [
            'saved' => true,
            'next' => $widget->placements()->exists()
                ? 'The dashboards it is on now draw this revision. Fix any data_failures with update_widget.'
                : 'Fix any data_failures with update_widget, then place it on a dashboard with place_widget (list_dashboards gives the ids, create_dashboard makes one).',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentWidget(WidgetDefinition $widget): array
    {
        return [
            'id' => $widget->id,
            'name' => $widget->name,
            'revision' => $widget->revision,
            'created_via' => $widget->created_via->value,
            'size' => $widget->manifest['size'],
            'last_error' => $widget->last_error === null ? null : [
                ...$widget->last_error,
                'stack' => $this->readableStack($widget->last_error['stack']),
                'component_stack' => $this->readableStack($widget->last_error['component_stack']),
                'at' => $widget->last_error_at?->toIso8601String(),
            ],
            'placements' => $widget->placements->sortBy('position')->values()
                ->map(fn (DashboardWidget $placement): array => $this->presentPlacement($placement))
                ->all(),
        ];
    }

    /**
     * A stack the dashboard caught, cut to its first frames, with the
     * module's own URL (a data: URL holding the whole compiled widget,
     * thousands of characters) shortened to `widget.js`.
     */
    private function readableStack(?string $stack): ?string
    {
        if ($stack === null) {
            return null;
        }

        $lines = array_slice(explode("\n", $stack), 0, 8);

        return (string) preg_replace('#data:text/javascript[^\s]*?(?=:\d+:\d+)#', 'widget.js', implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentPlacement(DashboardWidget $placement): array
    {
        return [
            'placement_id' => $placement->id,
            'dashboard_id' => $placement->dashboard_id,
            'widget_id' => $placement->widget_definition_id,
            'system_widget' => $placement->system_widget?->value,
            'width' => $placement->width->value,
            'height' => $placement->height->value,
            'position' => $placement->position,
        ];
    }

    /**
     * The whole widget, its code included, the data calls the API refuses,
     * and the preview in `_meta` for the MCP App.
     *
     * @param  array<string, mixed>  $extra  goes before the widget in the answer
     */
    protected function widgetResponse(User $user, WidgetDefinition $widget, array $extra = []): ResponseFactory
    {
        $preview = app(WidgetPreviewPayload::class)->for($user, $widget);

        $body = [
            ...$extra,
            'data_failures' => $preview['failures'],
            'widget' => [
                ...$this->presentWidget($widget),
                'manifest' => $widget->manifest,
                'source' => $widget->source,
                'revisions' => $widget->revisions()->orderByDesc('number')->get()
                    ->map(fn (WidgetRevision $revision): array => [
                        'number' => $revision->number,
                        'created_via' => $revision->created_via->value,
                        'restored_from' => $revision->restored_from,
                        'created_at' => $revision->created_at?->toIso8601String(),
                    ])->all(),
            ],
        ];

        return Response::make(Response::text((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->withMeta(WidgetPreviewPayload::MetaKey, $preview);
    }
}
