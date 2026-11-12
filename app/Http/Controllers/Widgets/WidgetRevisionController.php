<?php

namespace App\Http\Controllers\Widgets;

use App\Enums\WidgetOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widgets\RevertWidgetRequest;
use App\Models\WidgetDefinition;
use App\Services\Widgets\InvalidWidget;
use App\Services\Widgets\WidgetDefinitionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Takes a user widget back to an earlier revision, from the dashboard.
 */
class WidgetRevisionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WidgetDefinitionService $widgets) {}

    public function revert(RevertWidgetRequest $request, WidgetDefinition $widget): RedirectResponse
    {
        $this->authorize('update', $widget);

        try {
            $this->widgets->revert($widget, (int) $request->validated('revision'), WidgetOrigin::Web);
        } catch (InvalidWidget $exception) {
            throw $exception->toValidationException('revision');
        }

        return back();
    }
}
