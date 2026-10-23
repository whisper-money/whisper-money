<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SuggestImportColumnsRequest;
use App\Services\Ai\ImportColumnSuggester;
use Illuminate\Http\JsonResponse;

/**
 * AI suggestions for which column of an imported file holds each field. When
 * the AI cannot answer, `data` is null and the client keeps its own mapping.
 */
class ImportColumnSuggestionController extends Controller
{
    public function __construct(private ImportColumnSuggester $suggester) {}

    public function __invoke(SuggestImportColumnsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->suggester->suggest($request->kind(), $request->columns()),
        ]);
    }
}
