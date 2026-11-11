<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widgets\WidgetDataRequest;
use App\Models\Dashboard;
use App\Models\WidgetDefinition;
use App\Services\Widgets\Gateway\WidgetDataGateway;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * The data user widgets read, resolved server side from their saved
 * manifests as the signed-in user ({@see WidgetDataGateway}).
 * Each call answers with the status and body `/api/v1` would give it, so one
 * failing call does not take the others down.
 */
class WidgetDataController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WidgetDefinitionService $widgets) {}

    /**
     * One widget's data, by the keys of its manifest's `data`.
     */
    public function show(WidgetDataRequest $request, WidgetDefinition $widget): JsonResponse
    {
        $this->authorize('view', $widget);

        $data = $this->widgets->data($request->user(), [$widget], $request->variables());

        return response()->json(['revision' => $widget->revision, 'data' => $data[$widget->id]]);
    }

    /**
     * The data of every user widget on $dashboard, by widget id, in one go.
     */
    public function dashboard(WidgetDataRequest $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorize('view', $dashboard);

        $widgets = WidgetDefinition::query()
            ->onDashboard($dashboard)
            ->get();

        $data = $this->widgets->data($request->user(), $widgets, $request->variables());

        return response()->json([
            'widgets' => $widgets->mapWithKeys(fn (WidgetDefinition $widget): array => [$widget->id => [
                'revision' => $widget->revision,
                'data' => $data[$widget->id],
            ]])->all(),
        ]);
    }
}
