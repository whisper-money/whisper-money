<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSavedFilterRequest;
use App\Http\Requests\Api\UpdateSavedFilterRequest;
use App\Models\SavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $savedFilters = SavedFilter::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'filters']);

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
            'data' => $savedFilter->only(['id', 'name', 'filters']),
        ], 201);
    }

    public function update(UpdateSavedFilterRequest $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->user_id === $request->user()->id, 403);

        $savedFilter->update(['filters' => $request->validated('filters')]);

        return response()->json([
            'data' => $savedFilter->only(['id', 'name', 'filters']),
        ]);
    }

    public function destroy(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        abort_unless($savedFilter->user_id === $request->user()->id, 403);

        $savedFilter->delete();

        return response()->json(['message' => 'Saved filter deleted']);
    }
}
