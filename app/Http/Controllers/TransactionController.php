<?php

namespace App\Http\Controllers;

use App\Enums\CategorySource;
use App\Http\Requests\BulkUpdateTransactionsRequest;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Jobs\RecalculateHistoricalBalancesJob;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryOverrideHandler;
use App\Services\Banking\BalanceSyncService;
use App\Services\ManualBalanceAdjuster;
use App\Services\TransactionDayTotals;
use App\Services\TransactionFilterSummary;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(IndexTransactionRequest $request, TransactionDayTotals $dayTotals, TransactionFilterSummary $filterSummary): Response
    {
        $user = $request->user();
        $validated = $request->validated();

        $lastVisitAt = $user->transactions_last_visited_at;

        $perPage = (int) ($validated['per_page'] ?? 50);

        $filters = $request->filters();

        $filtered = Transaction::query()
            ->where('user_id', $user->id)
            ->applyFilters($filters);

        $transactions = (clone $filtered)
            ->with(['account.bank', 'category', 'labels', 'categorizedByRule:id,origin', 'splits.category'])
            ->sortedForList()
            ->cursorPaginate($perPage, cursor: $request->listCursor())
            ->withQueryString();

        $transactions->getCollection()->each->append(['ai_categorized', 'is_split', 'split_count']);

        Transaction::loadSplitSiblings($transactions->getCollection());

        $newestServed = $transactions->getCollection()->max('created_at');
        if ($newestServed && (! $lastVisitAt || $newestServed->gt($lastVisitAt))) {
            $user->forceFill(['transactions_last_visited_at' => $newestServed])->save();
        }

        $appliedFilters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            // The query string hands these over as strings; the page types them as numbers.
            'amount_min' => isset($validated['amount_min']) ? (float) $validated['amount_min'] : null,
            'amount_max' => isset($validated['amount_max']) ? (float) $validated['amount_max'] : null,
            'category_ids' => $validated['category_ids'] ?? [],
            'category_types' => $validated['category_types'] ?? [],
            'account_ids' => $validated['account_ids'] ?? [],
            'label_ids' => $validated['label_ids'] ?? [],
            'creditor_name' => $validated['creditor_name'] ?? '',
            'debtor_name' => $validated['debtor_name'] ?? '',
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? '',
            'cashflow_block' => $validated['cashflow_block'] ?? null,
        ];

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        $automationRules = AutomationRule::query()
            ->where('user_id', $user->id)
            ->with(['category', 'labels'])
            ->orderBy('priority')
            ->get();

        return Inertia::render('transactions/index', [
            'transactions' => $transactions,
            // Asked for beside `transactions` on every page load, it covers
            // the days that page touches.
            'dayTotals' => fn (): array => $dayTotals->forDays(
                $filtered,
                $transactions->getCollection()->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString()),
                $user->currency_code,
                $filters,
            ),
            // Only a filtered list gets a summary, after the rows: it adds up
            // every matching row, not the page served.
            'filterSummary' => $request->hasFilters()
                ? Inertia::defer(fn (): array => $filterSummary->summarize($filters, $user->currency_code))
                : null,
            'appliedFilters' => $appliedFilters,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'automationRules' => $automationRules,
            'hasAiConsent' => $user->hasActiveAiConsent(),
            'aiConsentPromptDismissed' => $user->hasDismissedAiConsentPrompt(),
            'lastVisitAt' => $lastVisitAt?->toISOString(),
        ]);
    }

    public function categorize(Request $request): Response
    {
        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereDoesntHave('splits')
            ->with(['account.bank', 'labels'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('transactions/categorize', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'transactions' => $transactions,
        ]);
    }

    public function store(StoreTransactionRequest $request, ManualBalanceAdjuster $balanceAdjuster): JsonResponse
    {
        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        unset($data['label_ids']);

        $transaction = new Transaction([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        if (isset($data['id'])) {
            $transaction->id = $data['id'];
            $transaction->exists = false;
        }

        DB::transaction(function () use ($request, $transaction, $labelIds, $balanceAdjuster): void {
            $transaction->save();

            if ($labelIds !== null) {
                $transaction->labels()->sync($labelIds);
            }

            if ($request->boolean('update_balance')) {
                $balanceAdjuster->applyCreatedTransaction($transaction);
            }
        }, attempts: 5);

        $this->recalculateBalanceHistoryIfBackdated($transaction);

        return response()->json([
            'data' => $transaction->fresh()->load(['labels', 'splits.category'])->append(['is_split', 'split_count']),
        ], 201);
    }

    /**
     * A transaction still split into the old lines is filed under them, so
     * its category and amount cannot change directly. Checked twice on
     * purpose: once before the work starts, and again under the row lock,
     * where lines written concurrently would otherwise slip through.
     */
    private function guardSplitLedgerChange(Request $request, Transaction $transaction): void
    {
        if (! $request->hasAny(['category_id', 'amount'])) {
            return;
        }

        if (! $transaction->splits()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'splits' => 'This transaction is still split into lines the old way, so its category and amount belong to those lines until it is converted to the new split model.',
        ]);
    }

    /**
     * A user-set category overrides any AI assignment: learn the correction as a
     * forward-looking rule, log/self-heal as needed, and reset the provenance
     * columns on `$data` so the save carries them.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordCategoryOverride(Transaction $transaction, array &$data): ?AutomationRule
    {
        $newCategoryId = $data['category_id'] ?? null;

        if ($newCategoryId === $transaction->category_id) {
            return null;
        }

        $data['category_source'] = $newCategoryId === null ? null : CategorySource::Manual->value;
        $data['ai_confidence'] = null;
        $data['categorized_by_rule_id'] = null;

        return app(CategoryOverrideHandler::class)->record($transaction, $newCategoryId);
    }

    /**
     * Persist the edit, labels included.
     *
     * A pivot change leaves the model itself clean, so labels-only edits are
     * touched into dirtiness on purpose: the `updated` event is what the
     * listeners downstream run on, and they have to see the new labels.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveWithLabels(Transaction $transaction, array $data, bool $hasLabelUpdate): void
    {
        if (! empty($data)) {
            $transaction->fill($data);
        }

        if ($transaction->isDirty() || $hasLabelUpdate) {
            if (! $transaction->isDirty()) {
                $transaction->touch();
            }
            $transaction->save();
        }
    }

    /**
     * Whether the edit moved anything the account's balance snapshots are built
     * from. Read off the raw originals so a cast does not make an unchanged
     * column look changed.
     */
    private function balanceFieldsChanged(Transaction $before, Transaction $after): bool
    {
        return collect(ManualBalanceAdjuster::BALANCE_AFFECTING_ATTRIBUTES)
            ->contains(fn (string $field): bool => $before->getRawOriginal($field) !== $after->getRawOriginal($field));
    }

    /**
     * Queue a rebuild of a connected account's derived balance history when a
     * transaction lands on a day that history already covers.
     *
     * Those balances are walked backwards from the bank's latest figure, so a
     * row dated inside the walked range changes every earlier day. In practice
     * that means an import: the flow posts one row at a time, and the job is
     * unique per account, so months of rows collapse into a single run.
     */
    private function recalculateBalanceHistoryIfBackdated(Transaction $transaction): void
    {
        $account = $transaction->account;

        if ($account === null || ! $account->isConnected()) {
            return;
        }

        if (! in_array($transaction->source, BalanceSyncService::WALKED_SOURCES, true)) {
            return;
        }

        $newestBalance = $account->balances()
            ->orderByDesc('balance_date')
            ->first();

        if ($newestBalance === null || ! $transaction->transaction_date->lt($newestBalance->balance_date)) {
            return;
        }

        RecalculateHistoricalBalancesJob::dispatch($account);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, ManualBalanceAdjuster $balanceAdjuster): JsonResponse
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        $hasLabelUpdate = $request->has('label_ids');
        unset($data['label_ids']);

        $learnedRule = null;

        $this->guardSplitLedgerChange($request, $transaction);

        DB::transaction(function () use (
            $request,
            $transaction,
            $data,
            $labelIds,
            $hasLabelUpdate,
            $balanceAdjuster,
            &$learnedRule,
        ): void {
            $transaction->setRawAttributes(
                Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail()->getAttributes(),
                true,
            );
            $originalSnapshot = clone $transaction;

            $this->guardSplitLedgerChange($request, $transaction);

            if ($request->has('category_id')) {
                $learnedRule = $this->recordCategoryOverride($transaction, $data);
            }

            if ($hasLabelUpdate) {
                $transaction->labels()->sync($labelIds ?? []);
                $transaction->load('labels');
            }

            $this->saveWithLabels($transaction, $data, $hasLabelUpdate);

            // Connected accounts and credit cards own no balance rows, so
            // the adjuster skips them.
            if ($request->boolean('update_balance') && $this->balanceFieldsChanged($originalSnapshot, $transaction)) {
                $balanceAdjuster->reverseDeletedTransaction($originalSnapshot);
                $balanceAdjuster->applyCreatedTransaction($transaction->load('account'));
            }
        }, attempts: 5);

        $updated = $transaction->fresh()->load(['category', 'account.bank', 'labels', 'categorizedByRule:id,origin', 'splits.category'])->append(['ai_categorized', 'is_split', 'split_count']);
        Transaction::loadSplitSiblings(collect([$updated]));

        return response()->json([
            'data' => $updated,
            'learned_rule' => $learnedRule === null ? null : [
                'id' => $learnedRule->id,
                'title' => $learnedRule->title,
                'category_id' => $learnedRule->action_category_id,
            ],
        ]);
    }

    public function destroy(Request $request, Transaction $transaction, ManualBalanceAdjuster $balanceAdjuster): JsonResponse
    {
        $this->authorize('delete', $transaction);

        // Removing one part on its own would leave the rest no longer adding up
        // to what the account actually moved.
        if ($transaction->isSplitPart()) {
            return response()->json([
                'message' => 'This transaction is part of a split. Merge the split back before deleting it.',
            ], 422);
        }

        if ($request->boolean('update_balance')) {
            $balanceAdjuster->reverseDeletedTransaction($transaction);
        }

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully',
        ]);
    }

    public function bulkUpdate(BulkUpdateTransactionsRequest $request): JsonResponse
    {
        $user = $request->user();
        $transactionIds = $request->input('transaction_ids');
        $filters = $request->input('filters');

        $updateData = $this->bulkUpdateData($request);
        $labelChange = $this->bulkLabelChange($request);

        if ($updateData === [] && $labelChange === null) {
            return response()->json([
                'message' => 'No update data provided.',
            ], 400);
        }

        $result = DB::transaction(function () use (
            $user,
            $transactionIds,
            $filters,
            $request,
            $updateData,
            $labelChange,
        ): array {
            $lockedTransactions = $this->lockBulkTargets($user, $transactionIds, $filters);

            if ($transactionIds && $lockedTransactions->count() !== count($transactionIds)) {
                abort(403, 'Some transactions were not found or do not belong to you.');
            }

            $categoryFields = ['category_id', 'category_source', 'ai_confidence', 'categorized_by_rule_id'];
            $categoryData = collect($updateData)->only($categoryFields)->all();
            $generalData = collect($updateData)->except($categoryFields)->all();

            // A split transaction is filed under its postings, so a bulk category
            // change has to leave it alone and say so in the response.
            $splitIds = $categoryData !== []
                ? $lockedTransactions->filter(fn (Transaction $candidate): bool => $candidate->splits->isNotEmpty())->pluck('id')
                : collect();

            $changedIds = collect();

            if ($categoryData !== []) {
                $candidates = $this->rowsNeedingUpdate($lockedTransactions->whereNotIn('id', $splitIds), $categoryData);
                $this->recordCategoryOverrides($candidates, $request->input('category_id'));
                $changedIds = $changedIds->merge($this->massUpdate($candidates, $categoryData));
            }

            if ($generalData !== []) {
                $candidates = $this->rowsNeedingUpdate($lockedTransactions, $generalData);
                $changedIds = $changedIds->merge($this->massUpdate($candidates, $generalData));
            }

            if ($labelChange !== null) {
                $changedIds = $changedIds->merge($this->changeBulkLabels($lockedTransactions, $labelChange));
            }

            $updatedIds = $changedIds->unique()->values();

            return [
                'count' => $lockedTransactions->count(),
                'updated_count' => $updatedIds->count(),
                'skipped_split_count' => $splitIds->count(),
                'updated_ids' => $updatedIds->all(),
                'skipped_split_ids' => $splitIds->values()->all(),
                'transactions' => $this->presentUpdated($user, $updatedIds),
            ];
        }, attempts: 5);

        // Neither branch above fires a model event — the category goes through a
        // query-builder mass update and the labels through the pivot — so nothing
        // else would re-derive which budgets now track these transactions.
        // Silently: editing existing rows is not new spending.
        if ($result['updated_ids'] !== []) {
            ReassignTransactionsToBudgets::dispatch($result['updated_ids'], notify: false);
        }

        return response()->json([
            'message' => 'Transactions updated successfully',
            ...$result,
        ]);
    }

    /**
     * The columns a bulk edit writes. A new category is a manual assignment, so
     * it clears any AI or rule provenance along with it.
     *
     * @return array<string, mixed>
     */
    private function bulkUpdateData(BulkUpdateTransactionsRequest $request): array
    {
        $updateData = [];

        if ($request->has('category_id')) {
            $newCategoryId = $request->input('category_id');
            $updateData['category_id'] = $newCategoryId;
            $updateData['category_source'] = $newCategoryId === null ? null : CategorySource::Manual->value;
            $updateData['ai_confidence'] = null;
            $updateData['categorized_by_rule_id'] = null;
        }

        foreach (['notes', 'notes_iv'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }

        return $updateData;
    }

    /**
     * The rows the edit applies to, locked in id order so concurrent bulk edits
     * queue instead of deadlocking.
     *
     * @param  array<int, string>|null  $transactionIds
     * @param  array<string, mixed>|null  $filters
     * @return EloquentCollection<int, Transaction>
     */
    private function lockBulkTargets(User $user, ?array $transactionIds, ?array $filters): EloquentCollection
    {
        $query = Transaction::query()->where('user_id', $user->id);

        if ($transactionIds !== null && $transactionIds !== []) {
            $query->whereIn('id', $transactionIds);
        } elseif ($filters !== null) {
            $query->applyFilters([...$filters, 'user_id' => $user->id]);
        }

        return $query
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->load('splits');
    }

    /**
     * The rows whose stored value actually differs from what is being written.
     * Comparing raw originals keeps a no-op edit out of the updated set.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     * @param  array<string, mixed>  $data
     * @return EloquentCollection<int, Transaction>
     */
    private function rowsNeedingUpdate(EloquentCollection $transactions, array $data): EloquentCollection
    {
        return $transactions->filter(
            fn (Transaction $candidate): bool => collect($data)->contains(
                fn (mixed $value, string $field): bool => $candidate->getRawOriginal($field) !== $value,
            ),
        );
    }

    /**
     * @param  EloquentCollection<int, Transaction>  $candidates
     * @param  array<string, mixed>  $data
     * @return Collection<int, string> the ids that were written
     */
    private function massUpdate(EloquentCollection $candidates, array $data): Collection
    {
        if ($candidates->isEmpty()) {
            return collect();
        }

        Transaction::query()->whereIn('id', $candidates->pluck('id'))->update($data);

        return $candidates->pluck('id');
    }

    /**
     * @param  EloquentCollection<int, Transaction>  $candidates
     */
    private function recordCategoryOverrides(EloquentCollection $candidates, ?string $newCategoryId): void
    {
        $overrideHandler = app(CategoryOverrideHandler::class);

        foreach ($candidates as $candidate) {
            if ($candidate->category_id !== $newCategoryId) {
                $overrideHandler->record($candidate, $newCategoryId);
            }
        }
    }

    /**
     * What a bulk edit does to each row's labels, or null when it leaves them
     * alone. Labels go on top of the ones a row has. Taking them all off is the
     * request's explicit `remove_all_labels`, so an add can never clear a row.
     *
     * @return (Closure(Transaction): array{attached: array<mixed>, detached: array<mixed>, updated: array<mixed>})|null
     */
    private function bulkLabelChange(BulkUpdateTransactionsRequest $request): ?Closure
    {
        if ($request->boolean('remove_all_labels')) {
            return fn (Transaction $transaction): array => $transaction->labels()->sync([]);
        }

        $labelIds = $request->input('label_ids') ?? [];

        return $labelIds === []
            ? null
            : fn (Transaction $transaction): array => $transaction->labels()->syncWithoutDetaching($labelIds);
    }

    /**
     * @param  EloquentCollection<int, Transaction>  $transactions
     * @param  Closure(Transaction): array{attached: array<mixed>, detached: array<mixed>, updated: array<mixed>}  $change
     * @return Collection<int, string> the ids whose labels actually moved
     */
    private function changeBulkLabels(EloquentCollection $transactions, Closure $change): Collection
    {
        $changed = collect();

        foreach ($transactions as $transaction) {
            $changes = $change($transaction);

            if ($changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== []) {
                $transaction->touch();
                $changed->push($transaction->id);
            }
        }

        return $changed;
    }

    /**
     * @param  Collection<int, string>  $updatedIds
     * @return EloquentCollection<int, Transaction>
     */
    private function presentUpdated(User $user, Collection $updatedIds): EloquentCollection
    {
        $updated = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $updatedIds)
            ->with(['category', 'account.bank', 'labels', 'categorizedByRule:id,origin', 'splits.category'])
            ->orderBy('id')
            ->get();

        $updated->each->append(['ai_categorized', 'is_split', 'split_count']);
        Transaction::loadSplitSiblings($updated);

        return $updated;
    }
}
