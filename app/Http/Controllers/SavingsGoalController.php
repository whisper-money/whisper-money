<?php

namespace App\Http\Controllers;

use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Requests\SyncSavingsGoalTransactionsRequest;
use App\Http\Requests\UpdateSavingsGoalRequest;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SavingsGoalController extends Controller
{
    use AuthorizesRequests;

    // ponytail: a flat window the dialog widens with its load-more link, no search.
    // If people still can't find older contributions, it needs a search box hitting
    // the transactions query instead.
    private const RECENT_TRANSACTIONS_LIMIT = 50;

    /**
     * How far the dialog's load-more link may widen the window. Past this, the flat
     * list stops being a usable way to find anything.
     */
    private const RECENT_TRANSACTIONS_MAX = 500;

    /**
     * What a transaction row needs to render, both in the page's list and in the
     * link dialog. Keep the two in step: the dialog merges both sets.
     *
     * @var list<string>
     */
    private const TRANSACTION_RELATIONS = ['account.bank', 'category', 'labels'];

    public function store(StoreSavingsGoalRequest $request): RedirectResponse
    {
        $goal = DB::transaction(function () use ($request) {
            $label = $request->user()->labels()->create([
                'name' => $request->name,
                'color' => LabelColor::Emerald->value,
                'source' => LabelSource::SavingsGoal,
            ]);

            return $request->user()->savingsGoals()->create([
                'label_id' => $label->id,
                'name' => $request->name,
                'target_amount' => $request->target_amount,
                // Nullable input coerced to 0 cents: the column is NOT NULL.
                'initial_amount' => $request->integer('initial_amount'),
                'target_date' => $request->target_date,
            ]);
        });

        return redirect()->route('savings-goals.show', $goal);
    }

    public function show(Request $request, SavingsGoal $savingsGoal): Response
    {
        $this->authorize('view', $savingsGoal);

        $user = $request->user();
        // Trashed too: archiving deletes the label, and without it the page
        // would silently drop the very contributions it is there to show.
        $savingsGoal->load(['label' => fn ($query) => $query->withTrashed()]);

        $transactions = $savingsGoal->label
            ? $savingsGoal->label->transactions()
                ->with(self::TRANSACTION_RELATIONS)
                ->orderBy('transaction_date')
                ->get()
            : collect();

        $stats = SavingsGoal::project(
            $savingsGoal->savedAmountInCents(),
            $savingsGoal->target_amount,
            SavingsGoal::effectiveStart($savingsGoal->created_at, $transactions->first()?->transaction_date),
            $savingsGoal->target_date,
            $savingsGoal->measuredAt(),
            $savingsGoal->initial_amount,
        );

        return Inertia::render('savings-goals/show', [
            'savingsGoal' => $savingsGoal,
            'transactions' => $transactions->values(),
            'stats' => $stats,
            'categories' => Category::query()
                ->where('user_id', $user->id)
                ->forDisplay()
                ->get(),
            'accounts' => Account::query()
                ->where('user_id', $user->id)
                ->with('bank')
                ->orderBy('name')
                ->get(),
            'banks' => Bank::query()
                ->availableForUser($user)
                ->orderBy('name')
                ->get(),
            'labels' => Label::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(),
            'currencyCode' => $user->currency_code ?? 'USD',
            // The dialog widens its window by this much per click. Sent rather than
            // hardcoded there: a client-side copy that drifted below this one would
            // silently hide the load-more link while more rows still existed.
            'recentPageSize' => self::RECENT_TRANSACTIONS_LIMIT,
            // Only fetched when the link-transactions dialog asks for it, which also
            // decides how wide a window it wants.
            'recentTransactions' => Inertia::optional(fn () => Transaction::query()
                ->where('user_id', $user->id)
                ->with(self::TRANSACTION_RELATIONS)
                ->orderByDesc('transaction_date')
                ->orderByDesc('created_at')
                ->limit($this->recentTransactionsLimit($request))
                ->get()),
        ]);
    }

    /**
     * The window the link dialog asked for, clamped to a size the flat list can still
     * carry. Anything unusable falls back to the first page instead of to no rows.
     */
    private function recentTransactionsLimit(Request $request): int
    {
        $requested = $request->integer('recent');

        return $requested < 1
            ? self::RECENT_TRANSACTIONS_LIMIT
            : min($requested, self::RECENT_TRANSACTIONS_MAX);
    }

    /**
     * Replace the set of transactions carrying this goal's label. The dialog always
     * shows every already-tagged transaction alongside the recent ones, so the ids it
     * sends back are the complete intended set.
     */
    public function syncTransactions(SyncSavingsGoalTransactionsRequest $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('update', $savingsGoal);

        // A goal whose label was soft-deleted has nothing to tag with.
        $label = $savingsGoal->label;

        if ($label === null) {
            return redirect()->route('savings-goals.show', $savingsGoal);
        }

        // Ids the user does not own are dropped by the scope rather than trusted.
        $ids = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $request->array('transaction_ids'))
            ->pluck('id')
            ->all();

        $changes = $label->transactions()->sync($ids);

        $touched = array_merge($changes['attached'], $changes['detached']);

        if ($touched !== []) {
            // Pivot writes fire no model event, so nothing would re-derive which
            // budgets track these transactions by label. Silently: relabelling an
            // existing transaction is not new spending.
            ReassignTransactionsToBudgets::dispatch($touched, notify: false);
        }

        // Named route, not back(): the dialog only exists on this page, and the
        // previous-url redirect resolves to the app's internal host behind a proxy.
        return redirect()->route('savings-goals.show', $savingsGoal);
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('update', $savingsGoal);

        DB::transaction(function () use ($request, $savingsGoal) {
            $savingsGoal->update($request->only(['name', 'target_amount', 'initial_amount', 'target_date']));

            if ($request->has('name') && $savingsGoal->label) {
                $savingsGoal->label->update(['name' => $request->name]);
            }
        });

        return redirect()->route('savings-goals.show', $savingsGoal);
    }

    /**
     * Archiving is one-way and freezes the goal.
     *
     * Its label goes with it — the goal is done, so the label must never be
     * pickable again, and soft-deleting it takes it out of every picker at once
     * through the global scope. That also means the saved amount can no longer
     * be derived (the sum would collapse to the starting balance, and re-tagging
     * one of those transactions later would move a final figure), so it is
     * snapshotted here. Both writes and the archive date share one transaction:
     * a goal that is half-archived has no meaning.
     */
    public function archive(Request $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('archive', $savingsGoal);

        DB::transaction(function () use ($savingsGoal) {
            // Read before the archive date is written: savedAmountInCents()
            // switches to the snapshot the moment the goal counts as archived.
            $saved = $savingsGoal->savedAmountInCents();

            $savingsGoal->update([
                'archived_at' => now(),
                'archived_saved_amount' => $saved,
            ]);

            $savingsGoal->label?->delete();
        });

        // Named route, not back(): see syncTransactions — the previous-url
        // redirect resolves to the app's internal host behind a proxy.
        return redirect()->route('savings-goals.show', $savingsGoal);
    }

    public function destroy(Request $request, SavingsGoal $savingsGoal): RedirectResponse
    {
        $this->authorize('delete', $savingsGoal);

        DB::transaction(function () use ($savingsGoal) {
            $savingsGoal->label?->delete();
            $savingsGoal->delete();
        });

        return redirect()->route('budgets.index');
    }
}
