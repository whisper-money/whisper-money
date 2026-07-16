<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnalysisMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSavedFilterRequest;
use App\Http\Requests\Api\UpdateSavedFilterRequest;
use App\Models\SavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $savedFilters = $request->user()->activeSpace()
            ->savedFilters()
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $savedFilters]);
    }

    public function store(StoreSavedFilterRequest $request): JsonResponse
    {
        $savedFilter = SavedFilter::query()->create([
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'filters' => $request->validated('filters'),
        ]);

        return response()->json([
            'data' => $savedFilter,
        ], 201);
    }

    public function update(UpdateSavedFilterRequest $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->space_id === $request->user()->activeSpace()->id, 403);

        $savedFilter->update(['filters' => $request->validated('filters')]);

        return response()->json([
            'data' => $savedFilter,
        ]);
    }

    public function destroy(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->space_id === $request->user()->activeSpace()->id, 403);

        $savedFilter->delete();

        return response()->json(['message' => 'Saved filter deleted']);
    }

    public function updateAnalysisDays(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->space_id === $request->user()->activeSpace()->id, 403);

        $validated = $request->validate([
            'analysis_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ]);

        $savedFilter->update(['analysis_days' => $validated['analysis_days'] ?? null]);

        return response()->json([
            'data' => $savedFilter,
        ]);
    }

    public function updateAnalysisMode(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->space_id === $request->user()->activeSpace()->id, 403);

        $validated = $request->validate([
            'analysis_mode' => ['nullable', Rule::enum(AnalysisMode::class)],
        ]);

        $savedFilter->update(['analysis_mode' => $validated['analysis_mode'] ?? null]);

        return response()->json([
            'data' => $savedFilter,
        ]);
    }
}
