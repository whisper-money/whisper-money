<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SuggestImportColumnsRequest;
use App\Services\Ai\ImportColumnSuggester;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * AI suggestions for which column of an imported file holds each field. When
 * the AI cannot answer, `data` is null and the client keeps its own mapping;
 * `cached` says the answer was served from an earlier identical request.
 */
class ImportColumnSuggestionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ImportColumnSuggester $suggester) {}

    public function __invoke(SuggestImportColumnsRequest $request): JsonResponse
    {
        $account = $request->account();

        if ($account !== null) {
            $this->authorize('view', $account);
        }

        return response()->json($this->suggester->suggestCached(
            $request->user(),
            $account,
            $request->kind(),
            $request->columns(),
        ));
    }
}
