<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widgets\StoreWidgetErrorRequest;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

/**
 * Keeps the runtime error a user widget raised on the dashboard, so the
 * agent that wrote it can read it and fix it without the user copying it.
 */
class WidgetErrorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WidgetDefinitionService $widgets) {}

    /**
     * 204 whether it was kept or dropped as stale: the page has nothing to do either way.
     */
    public function store(StoreWidgetErrorRequest $request, WidgetDefinition $widget): Response
    {
        $this->authorize('view', $widget);

        $this->widgets->recordError($widget, (int) $request->validated('revision'), $request->error());

        return response()->noContent();
    }
}
