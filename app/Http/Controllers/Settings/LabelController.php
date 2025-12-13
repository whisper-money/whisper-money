<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreLabelRequest;
use App\Http\Requests\Settings\UpdateLabelRequest;
use App\Models\Label;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LabelController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's labels settings page.
     */
    public function index(): Response
    {
        $labels = auth()->user()
            ->labels()
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('settings/labels', [
            'labels' => $labels,
        ]);
    }

    /**
     * Store a newly created label.
     */
    public function store(StoreLabelRequest $request): JsonResponse|RedirectResponse
    {
        $label = auth()->user()->labels()->create($request->validated());

        if ($request->expectsJson()) {
            return response()->json(['data' => $label], 201);
        }

        return to_route('labels.index');
    }

    /**
     * Update the specified label.
     */
    public function update(UpdateLabelRequest $request, Label $label): RedirectResponse
    {
        $this->authorize('update', $label);

        $label->update($request->validated());

        return to_route('labels.index');
    }

    /**
     * Soft delete the specified label.
     */
    public function destroy(Label $label): RedirectResponse
    {
        $this->authorize('delete', $label);

        $label->delete();

        return to_route('labels.index');
    }
}
