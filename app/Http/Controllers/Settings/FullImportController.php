<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\UndoImportJob;
use App\Models\Import;
use App\Services\Imports\ImportHistoryPresenter;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings › Import from another app: the way into the wizard, and the history
 * of imports with the button that takes one back out.
 */
class FullImportController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, ImportHistoryPresenter $presenter): Response
    {
        $user = $request->user();

        abort_unless($user->canSeeFullImportSettings(), 404);

        return Inertia::render('settings/full-import', [
            'canStart' => $user->canUseFullImport(),
            'windowEndsAt' => $user->fullImportWindowEndsAt()?->toIso8601String(),
            'imports' => $presenter->history($user),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->canUseFullImport()) {
            return to_route('full-import.index');
        }

        return Inertia::render('settings/full-import-wizard');
    }

    /**
     * Start undoing an import. Open past the window on purpose: an import made
     * inside it has to stay undoable after it closes. The deleting happens on
     * the queue (UndoImportJob); claiming the import with a conditional update
     * means two clicks never queue it twice.
     */
    public function destroy(Import $import): RedirectResponse
    {
        $this->authorize('delete', $import);

        $previousStatus = $import->status;

        $claimed = $import->isUndoable() && Import::query()
            ->whereKey($import->id)
            ->whereNull('undone_at')
            ->where('status', $previousStatus->value)
            ->update(['status' => ImportStatus::Undoing->value]) === 1;

        if (! $claimed) {
            return to_route('full-import.index')->withErrors(['import' => __('This import cannot be undone.')]);
        }

        $import->recordStats(['undo' => [
            'previous_status' => $previousStatus->value,
            'requested_at' => now()->toIso8601String(),
            'failed' => false,
        ]]);

        try {
            // Through dispatch(), so the job's unique lock is taken too.
            UndoImportJob::dispatch($import->refresh());
        } catch (Throwable $exception) {
            // Claimed but never queued, the import would stay "undoing" for
            // good: it goes back to how it was, and the user can try again.
            // dispatch() took the job's unique lock before failing; it is
            // released too, or the next try would be dropped for half an hour.
            report($exception);
            Import::query()->whereKey($import->id)->where('status', ImportStatus::Undoing->value)->update(['status' => $previousStatus->value]);
            (new UniqueLock(app(Cache::class)))->release(new UndoImportJob($import));

            return to_route('full-import.index')->withErrors(['import' => __('The undo could not be started. Try again.')]);
        }

        Log::info('Full import undo requested', $import->logContext(['previous_status' => $previousStatus->value]));

        return to_route('full-import.index')->with('success', __('The import is being undone.'));
    }
}
