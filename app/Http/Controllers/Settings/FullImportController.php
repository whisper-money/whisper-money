<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Services\Imports\ImportHistoryPresenter;
use App\Services\Imports\ImportUndoer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Undo an import. Open past the window on purpose: an import made inside
     * it has to stay undoable after it closes.
     */
    public function destroy(Import $import, ImportUndoer $undoer): RedirectResponse
    {
        $this->authorize('delete', $import);

        if (! $import->isUndoable()) {
            return to_route('full-import.index')->withErrors(['import' => __('This import cannot be undone.')]);
        }

        $undoer->undo($import);

        return to_route('full-import.index')->with('success', __('Import undone.'));
    }
}
