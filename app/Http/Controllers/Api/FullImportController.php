<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportChunkKind;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MatchFullImportBanksRequest;
use App\Http\Requests\Api\StoreFullImportChunkRequest;
use App\Http\Requests\Api\StoreFullImportRequest;
use App\Jobs\ProcessFullImportJob;
use App\Models\Bank;
use App\Models\Import;
use App\Models\User;
use App\Services\Imports\BankNameMatcher;
use App\Services\Imports\ImportHistoryPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $validated = $request->validated();

        $refusal = null;

        $import = $this->whileHoldingUser($user, function () use ($user, $validated, &$refusal): ?Import {
            $refusal = $this->refusalFor($user);

            if ($refusal !== null) {
                return null;
            }

            // An upload abandoned halfway left a draft behind with nothing
            // written from it yet; its staged rows go with it.
            $user->imports()->where('status', ImportStatus::Draft->value)->get()->each->delete();

            return $this->createDraft($user, $validated);
        });

        if ($import === null) {
            return $refusal ?? $this->alreadyRunning();
        }

        return response()->json($this->presenter->status($import), 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createDraft(User $user, array $validated): Import
    {
        return $user->imports()->create([
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
            'stats' => ['stage' => ImportStage::Upload->value],
        ]);
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

        // An upload still going is not an abandoned draft (see Import::prunable).
        $import->touch();

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

        // Held on the user row, with a conditional update: two starts sent at
        // once (a double click, a retried request, two tabs) must not both
        // queue a job, or the new accounts would be written twice.
        $refusal = null;

        $claimed = $this->whileHoldingUser($request->user(), function () use ($request, $import, &$refusal): bool {
            $refusal = $this->refusalFor($request->user());

            return $refusal === null && Import::query()
                ->whereKey($import->id)
                ->where('status', ImportStatus::Draft->value)
                ->update(['status' => ImportStatus::Queued->value]) === 1;
        });

        if (! $claimed) {
            return $refusal ?? $this->alreadyRunning();
        }

        $import->recordStage(ImportStage::Queued);

        ProcessFullImportJob::dispatch($import->refresh());

        return response()->json($this->presenter->status($import));
    }

    public function show(Import $import): JsonResponse
    {
        $this->authorize('view', $import);

        return response()->json($this->presenter->status($import));
    }

    /**
     * Run a check-then-write with the user's row locked, so "one import at a
     * time" holds when two requests arrive together.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function whileHoldingUser(User $user, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $callback): mixed {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            return $callback();
        });
    }

    /**
     * Why the user may not start an import right now, or null when they may:
     * one is already running, or an earlier one is still being undone (the
     * new one could map into an account that undo is about to delete).
     * Checked while holding the user row.
     */
    private function refusalFor(User $user): ?JsonResponse
    {
        if ($user->imports()->running()->exists()) {
            return $this->alreadyRunning();
        }

        if ($user->imports()->where('status', ImportStatus::Undoing->value)->exists()) {
            return response()->json(['message' => __('An earlier import is still being undone. Try again in a moment.')], 409);
        }

        return null;
    }

    private function alreadyRunning(): JsonResponse
    {
        return response()->json(['message' => __('An import is already running. Wait for it to finish first.')], 409);
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
