<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportChunkKind;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MatchFullImportBanksRequest;
use App\Http\Requests\Api\StoreFullImportChunkRequest;
use App\Http\Requests\Api\StoreFullImportRequest;
use App\Jobs\ProcessFullImportJob;
use App\Models\Bank;
use App\Models\Import;
use App\Services\Imports\BankNameMatcher;
use App\Services\Imports\ImportHistoryPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The full import wizard's backend. The browser parses the file; this takes
 * the plan, the rows in chunks, and starts the queued job that writes them.
 * Lives under `api/` on purpose: neither `onboarded` nor `subscribed` wraps
 * it, so a user still onboarding can run the whole import.
 */
class FullImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ImportHistoryPresenter $presenter) {}

    public function context(Request $request): JsonResponse
    {
        abort_unless($request->user()->canUseFullImport(), 403);

        return response()->json($this->presenter->context($request->user()));
    }

    public function bankMatches(MatchFullImportBanksRequest $request, BankNameMatcher $matcher): JsonResponse
    {
        $matches = $matcher->match($request->user(), $request->validated('names'));

        return response()->json([
            'matches' => array_map(fn (?Bank $bank): ?array => $bank?->only(['id', 'name', 'logo']), $matches),
        ]);
    }

    public function store(StoreFullImportRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->imports()->running()->exists()) {
            return response()->json(['message' => __('An import is already running. Wait for it to finish first.')], 409);
        }

        // An upload abandoned halfway left a draft behind with nothing written
        // from it yet; its staged rows go with it.
        $user->imports()->where('status', ImportStatus::Draft->value)->delete();

        $validated = $request->validated();

        $import = $user->imports()->create([
            'space_id' => $user->activeSpace()->id,
            'source' => $validated['source'],
            'file_name' => $validated['file_name'] ?? null,
            'mode' => $validated['mode'],
            'status' => ImportStatus::Draft,
            'plan' => [
                'accounts' => $validated['accounts'],
                'categories' => $validated['categories'],
                'expected' => [
                    ImportChunkKind::Transactions->value => (int) $validated['expected_transactions'],
                    ImportChunkKind::Balances->value => (int) $validated['expected_balances'],
                ],
            ],
            'options' => ['profile' => $validated['profile'] ?? null],
            'stats' => ['stage' => 'upload'],
        ]);

        return response()->json($this->presenter->status($import), 201);
    }

    public function storeChunk(StoreFullImportChunkRequest $request, Import $import): JsonResponse
    {
        $this->authorizeDraft($request, $import);

        $kind = ImportChunkKind::from($request->validated('kind'));
        $rows = $request->validated('rows');

        // Keyed on the position, so a chunk the browser re-sends after a
        // dropped response replaces itself instead of doubling up.
        $import->chunks()->updateOrCreate(
            ['kind' => $kind->value, 'position' => $request->validated('position')],
            ['rows' => $rows, 'row_count' => count($rows)],
        );

        return response()->json(['received' => $import->stagedRowCount($kind)]);
    }

    public function start(Request $request, Import $import): JsonResponse
    {
        $this->authorizeDraft($request, $import);

        foreach (ImportChunkKind::cases() as $kind) {
            if ($import->stagedRowCount($kind) !== (int) ($import->plan['expected'][$kind->value] ?? 0)) {
                return response()->json(['message' => __('Some rows did not reach the server. Try the import again.')], 422);
            }
        }

        $import->forceFill(['status' => ImportStatus::Queued])->save();
        $import->recordStats(['stage' => 'queued']);

        ProcessFullImportJob::dispatch($import);

        return response()->json($this->presenter->status($import->refresh()));
    }

    public function show(Import $import): JsonResponse
    {
        $this->authorize('view', $import);

        return response()->json($this->presenter->status($import));
    }

    /**
     * Rows are only taken while the import is still being uploaded, and only
     * from a user who may still import: the window can close mid-upload.
     */
    private function authorizeDraft(Request $request, Import $import): void
    {
        $this->authorize('update', $import);

        abort_unless($request->user()->canUseFullImport(), 403);
        abort_unless($import->status === ImportStatus::Draft, 409, __('This import has already started.'));
    }
}
