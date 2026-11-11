<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Resources\WidgetPreview;
use App\Models\Account;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use App\Services\Widgets\Gateway\DashboardVariables;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetDefinitionService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * The dashboard and widget shapes the dashboard tools return, how they find
 * the caller's own rows, and the preview payload the widget tools hand to
 * the {@see WidgetPreview} MCP App. Dashboards and widgets belong to one user:
 * another user's id is refused like one that does not exist.
 */
trait PresentsWidgets
{
    /**
     * Where the preview payload goes in a tool result's `_meta`: the MCP App
     * reads it there, and the agent never pays for the compiled module and
     * the data in its context. resources/js/widgets/preview/main.tsx reads
     * the same key.
     */
    private const string PreviewMetaKey = 'whisper-money/widget-preview';

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
    protected function invalidWidget(InvalidWidget $exception): Response
    {
        return Response::error((string) json_encode([
            'saved' => false,
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
                ? 'The dashboards it is on now draw this revision. Call get_widget later to see whether it raised a runtime error.'
                : 'Place it on a dashboard with place_widget (list_dashboards gives the ids, create_dashboard makes one).',
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
                'at' => $widget->last_error_at?->toIso8601String(),
            ],
            'placements' => $widget->placements()->orderBy('position')->get()
                ->map(fn (DashboardWidget $placement): array => $this->presentPlacement($placement))
                ->all(),
        ];
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
     * The whole widget, its code included, with the preview attached.
     *
     * @param  array<string, mixed>  $extra  goes before the widget in the answer
     */
    protected function widgetResponse(User $user, WidgetDefinition $widget, array $extra = []): ResponseFactory
    {
        $body = [
            ...$extra,
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
            ->withMeta(self::PreviewMetaKey, $this->previewPayload($user, $widget));
    }

    /**
     * What the preview needs to draw the widget as the dashboard would: its
     * compiled module, its data resolved through the gateway for the user's
     * current month (each call's body, as the dashboard hands it over), and
     * the user's currency, locale and accounts.
     *
     * @return array<string, mixed>
     */
    private function previewPayload(User $user, WidgetDefinition $widget): array
    {
        $answers = app(WidgetDefinitionService::class)
            ->data($user, [$widget], DashboardVariables::forMonthOf(CarbonImmutable::instance($user->localToday())))[$widget->id];

        $failures = [];

        foreach ($answers as $key => $answer) {
            if ($answer['status'] >= 400) {
                $failures[] = ['key' => $key, 'status' => $answer['status'], 'message' => $answer['body']['message'] ?? "The call failed with status {$answer['status']}."];
            }
        }

        return [
            'widget_id' => $widget->id,
            'name' => $widget->name,
            'revision' => $widget->revision,
            'js' => $widget->compiled_js,
            'size' => array_map(fn (BackedEnum $value): string => (string) $value->value, $widget->defaultSize()),
            'data' => array_map(fn (array $answer): mixed => $answer['body'], $answers),
            'failures' => $failures,
            'currency' => $user->currency_code ?? 'EUR',
            'locale' => $user->formatLocale(),
            'accounts' => $user->accounts()->with('bank:id,name,logo')->get()
                ->mapWithKeys(fn (Account $account): array => [$account->id => [
                    'type' => $account->type->value,
                    'bankName' => $account->bank?->name,
                    'bankLogo' => $account->bank?->logo,
                ]])->all(),
        ];
    }
}
