<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\UndoImportJob;
use App\Models\Import;
use App\Services\Imports\ImportHistoryPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

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

        Log::info('Full import undo requested', $import->logContext(['previous_status' => $previousStatus->value]));

        UndoImportJob::dispatch($import->refresh());

        return to_route('full-import.index')->with('success', __('The import is being undone.'));
    }
}
