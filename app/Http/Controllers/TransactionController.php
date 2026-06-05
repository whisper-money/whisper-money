<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateTransactionsRequest;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\ManualBalanceAdjuster;
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
            'search' => $validated['search'] ?? null,
        ], fn ($value) => $value !== null);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->with(['account.bank:id,name,logo', 'category:id,name,icon,color', 'labels:id,name,color'])
            ->applyFilters($filters)
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage)
            ->withQueryString();

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
            'search' => $validated['search'] ?? '',
            'sort' => $sortParam,
        ];

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $automationRules = AutomationRule::query()
            ->where('user_id', $user->id)
            ->with(['category:id,name,icon,color', 'labels:id,name,color'])
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
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->with(['account.bank:id,name,logo', 'labels:id,name,color'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get(['id', 'account_id', 'category_id', 'description', 'description_iv', 'transaction_date', 'amount', 'currency_code', 'notes', 'notes_iv', 'creditor_name', 'debtor_name']);

        return Inertia::render('transactions/categorize', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'transactions' => $transactions,
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
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

        return response()->json([
            'data' => $transaction->load('labels:id,name,color'),
        ], 201);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        $hasLabelUpdate = $request->has('label_ids');
        unset($data['label_ids']);

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

        return response()->json([
            'data' => $transaction->fresh()->load('labels:id,name,color'),
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
        $user = $request->user();
        $transactionIds = $request->input('transaction_ids');
        $filters = $request->input('filters');

        $query = Transaction::query()->where('user_id', $user->id);

        if ($transactionIds && count($transactionIds) > 0) {
            $query->whereIn('id', $transactionIds);
            $transactions = $query->get();

            if ($transactions->count() !== count($transactionIds)) {
                return response()->json([
                    'message' => 'Some transactions were not found or do not belong to you.',
                ], 403);
            }
        } elseif ($filters !== null) {
            $query->applyFilters($filters);
            $transactions = $query->get();
        } else {
            $transactions = $query->get();
        }

        $updateData = [];
        if ($request->has('category_id')) {
            $updateData['category_id'] = $request->input('category_id');
        }
        if ($request->has('notes')) {
            $updateData['notes'] = $request->input('notes');
        }
        if ($request->has('notes_iv')) {
            $updateData['notes_iv'] = $request->input('notes_iv');
        }

        $labelIds = $request->input('label_ids');
        $hasLabelUpdate = $request->has('label_ids');

        if (empty($updateData) && ! $hasLabelUpdate) {
            return response()->json([
                'message' => 'No update data provided.',
            ], 400);
        }

        if (! empty($updateData)) {
            $updateQuery = Transaction::query()->where('user_id', $user->id);
            if ($transactionIds && count($transactionIds) > 0) {
                $updateQuery->whereIn('id', $transactionIds);
            } elseif ($filters !== null) {
                $updateQuery->applyFilters($filters);
            }
            $updateQuery->update($updateData);
        }

        if ($hasLabelUpdate) {
            foreach ($transactions as $transaction) {
                $transaction->labels()->sync($labelIds ?? []);
                $transaction->save();
            }
        }

        return response()->json([
            'message' => 'Transactions updated successfully',
            'count' => $transactions->count(),
        ]);
    }
}
