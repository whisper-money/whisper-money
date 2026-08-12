<?php

namespace App\Http\Controllers;

use App\Enums\CategorySource;
use App\Http\Requests\BulkUpdateTransactionsRequest;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\Ai\CategoryOverrideHandler;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(IndexTransactionRequest $request): Response
    {
        $user = $request->user();
        $validated = $request->validated();

        $lastVisitAt = $user->transactions_last_visited_at;

        $perPage = (int) ($validated['per_page'] ?? 50);
        $sortParam = $validated['sort'] ?? '-transaction_date';

        $descending = str_starts_with($sortParam, '-');
        $sortColumn = ltrim($sortParam, '-');
        $sortDirection = $descending ? 'desc' : 'asc';

        $filters = array_filter([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? null,
            'account_ids' => $validated['account_ids'] ?? null,
            'label_ids' => $validated['label_ids'] ?? null,
            'creditor_name' => $validated['creditor_name'] ?? null,
            'debtor_name' => $validated['debtor_name'] ?? null,
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? null,
        ], fn ($value) => $value !== null);

        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->with(['account.bank', 'category', 'labels', 'categorizedByRule:id,origin'])
            ->applyFilters($filters);

        $nullableSortColumns = ['creditor_name', 'debtor_name'];

        if (in_array($sortColumn, $nullableSortColumns, true)) {
            $sortAlias = $sortColumn.'_sort';
            $query->select('transactions.*')
                ->selectRaw("COALESCE({$sortColumn}, '') as {$sortAlias}")
                ->orderBy($sortAlias, $sortDirection);
        } else {
            $query->orderBy($sortColumn, $sortDirection);
        }

        $transactions = $query
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage)
            ->withQueryString();

        $transactions->getCollection()->each(function (Transaction $transaction): void {
            $transaction->makeHidden(['creditor_name_sort', 'debtor_name_sort'])
                ->append('ai_categorized');
        });

        $newestServed = $transactions->getCollection()->max('created_at');
        if ($newestServed && (! $lastVisitAt || $newestServed->gt($lastVisitAt))) {
            $user->forceFill(['transactions_last_visited_at' => $newestServed])->save();
        }

        $appliedFilters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? [],
            'account_ids' => $validated['account_ids'] ?? [],
            'label_ids' => $validated['label_ids'] ?? [],
            'creditor_name' => $validated['creditor_name'] ?? '',
            'debtor_name' => $validated['debtor_name'] ?? '',
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? '',
            'sort' => $sortParam,
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

        $transaction->save();

        if ($labelIds !== null) {
            $transaction->labels()->sync($labelIds);
        }

        if ($request->boolean('update_balance')) {
            $balanceAdjuster->applyCreatedTransaction($transaction);
        }

        return response()->json([
            'data' => $transaction->load('labels'),
        ], 201);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, ManualBalanceAdjuster $balanceAdjuster): JsonResponse
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        $hasLabelUpdate = $request->has('label_ids');
        unset($data['label_ids']);

        $learnedRule = null;

        // A user-set category overrides any AI assignment: learn the correction as
        // a forward-looking rule, log/self-heal as needed, and reset provenance.
        if ($request->has('category_id')) {
            $newCategoryId = $data['category_id'] ?? null;

            if ($newCategoryId !== $transaction->category_id) {
                $learnedRule = app(CategoryOverrideHandler::class)->record($transaction, $newCategoryId);

                $data['category_source'] = $newCategoryId === null ? null : CategorySource::Manual->value;
                $data['ai_confidence'] = null;
                $data['categorized_by_rule_id'] = null;
            }
        }

        // Snapshot the pre-edit account/date/amount before filling, so a manual
        // account balance can be moved off the old values if the edit changes them.
        $originalSnapshot = clone $transaction;

        // Update attributes directly without firing events yet
        if (! empty($data)) {
            $transaction->fill($data);
        }

        // Sync labels if provided
        if ($hasLabelUpdate) {
            $transaction->labels()->sync($labelIds ?? []);
            // Reload labels so the event listener has fresh data
            $transaction->load('labels');
        }

        // Save to fire the updated event if there are any changes
        // We need to save even if just labels changed (isDirty won't detect pivot changes)
        if ($transaction->isDirty() || $hasLabelUpdate) {
            // Touch the model to ensure it's marked as changed for the event
            if (! $transaction->isDirty() && $hasLabelUpdate) {
                $transaction->touch();
            }
            $transaction->save();
        }

        // Move the manual account balance to match an edited amount/date/account:
        // strip the pre-edit contribution (exactly as a deletion would) and apply
        // the new one (exactly as a creation would), both cascading forward.
        // ponytail: like create/delete, this trusts the opt-in flag and keeps no
        // record of whether creation adjusted the balance, so mixing the flag
        // across create and edit can drift; a transaction-derived balance would
        // remove that trust. Connected accounts are skipped inside the adjuster.
        if ($request->boolean('update_balance') && $transaction->wasChanged(['amount', 'transaction_date', 'account_id'])) {
            $balanceAdjuster->reverseDeletedTransaction($originalSnapshot);
            $balanceAdjuster->applyCreatedTransaction($transaction->load('account'));
        }

        return response()->json([
            'data' => $transaction->fresh()->load('labels'),
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
        // The request rules make transaction_ids either absent or a non-empty
        // array (`['array', 'min:1']`), so null here means "no explicit
        // selection" and the filters decide.
        $transactionIds = $request->input('transaction_ids');
        $filters = $request->input('filters');

        $transactions = $this->bulkSelection($request->user()->id, $transactionIds, $filters)->get();

        // Ids the user does not own are silently dropped by the user_id scope, so a
        // short result means they asked for someone else's rows.
        if ($transactionIds !== null && $transactions->count() !== count($transactionIds)) {
            return response()->json([
                'message' => 'Some transactions were not found or do not belong to you.',
            ], 403);
        }

        $updateData = $this->bulkUpdateAttributes($request, $transactions);
        $hasLabelUpdate = $request->has('label_ids');

        if ($updateData === [] && ! $hasLabelUpdate) {
            return response()->json([
                'message' => 'No update data provided.',
            ], 400);
        }

        if ($updateData !== []) {
            $this->bulkSelection($request->user()->id, $transactionIds, $filters)->update($updateData);
        }

        if ($hasLabelUpdate) {
            $this->syncLabels($transactions, $request->input('label_ids') ?? []);
        }

        // Neither branch above fires a model event — the category goes through a
        // query-builder mass update and the labels through the pivot — so nothing
        // else would re-derive which budgets now track these transactions.
        // Silently: editing existing rows is not new spending.
        ReassignTransactionsToBudgets::dispatch($transactions->pluck('id')->all(), notify: false);

        return response()->json([
            'message' => 'Transactions updated successfully',
            'count' => $transactions->count(),
        ]);
    }

    /**
     * The rows a bulk operation applies to: the explicit ids when the request
     * carries them, otherwise everything matching the filters the user has on
     * screen. Always scoped to the caller.
     *
     * @param  list<string>|null  $transactionIds
     * @param  array<string, mixed>|null  $filters
     * @return Builder<Transaction>
     */
    private function bulkSelection(string $userId, ?array $transactionIds, ?array $filters): Builder
    {
        $query = Transaction::query()->where('user_id', $userId);

        if ($transactionIds !== null) {
            return $query->whereIn('id', $transactionIds);
        }

        return $filters === null ? $query : $query->applyFilters($filters);
    }

    /**
     * The columns a bulk update writes, taken from the fields the request carries.
     *
     * A new category is a manual assignment, so any AI or rule provenance is
     * cleared. The previous value is recorded first, while the rows still hold it,
     * so the learning pipeline sees the correction.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function bulkUpdateAttributes(BulkUpdateTransactionsRequest $request, Collection $transactions): array
    {
        $updateData = [];

        if ($request->has('category_id')) {
            $newCategoryId = $request->input('category_id');
            $overrideHandler = app(CategoryOverrideHandler::class);

            foreach ($transactions as $transaction) {
                $overrideHandler->record($transaction, $newCategoryId);
            }

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
     * Labels are a relation, so they are replaced row by row rather than in the
     * mass update.
     *
     * The sync fires no model event, and neither does the save() — these models
     * were loaded before the mass update and nothing dirties them, so save() skips
     * performUpdate() entirely. bulkUpdate() queues the budget reassignment that
     * the missing event would have triggered.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  list<string>  $labelIds
     */
    private function syncLabels(Collection $transactions, array $labelIds): void
    {
        foreach ($transactions as $transaction) {
            $transaction->labels()->sync($labelIds);
            $transaction->save();
        }
    }
}
