<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateTransactionsRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color']);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'bank_id', 'type', 'currency_code']);

        $banks = Bank::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('transactions/index', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
        ]);
    }

    public function categorize(Request $request): Response
    {
        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color', 'type']);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'bank_id', 'type', 'currency_code']);

        $banks = Bank::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('transactions/categorize', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transaction = new Transaction([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        if (isset($data['id'])) {
            $transaction->id = $data['id'];
            $transaction->exists = false;
        }

        $transaction->save();

        return response()->json([
            'data' => $transaction,
        ], 201);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $transaction->update($request->validated());

        return response()->json([
            'data' => $transaction->fresh(),
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);

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
            if (isset($filters['date_from'])) {
                $query->whereDate('transaction_date', '>=', $filters['date_from']);
            }
            if (isset($filters['date_to'])) {
                $query->whereDate('transaction_date', '<=', $filters['date_to']);
            }
            if (isset($filters['amount_min'])) {
                $query->where('amount', '>=', $filters['amount_min'] * 100);
            }
            if (isset($filters['amount_max'])) {
                $query->where('amount', '<=', $filters['amount_max'] * 100);
            }
            if (! empty($filters['category_ids'])) {
                $query->whereIn('category_id', $filters['category_ids']);
            }
            if (! empty($filters['account_ids'])) {
                $query->whereIn('account_id', $filters['account_ids']);
            }
            if (! empty($filters['label_ids'])) {
                $query->whereHas('labels', function ($q) use ($filters) {
                    $q->whereIn('labels.id', $filters['label_ids']);
                });
            }

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
            $query = Transaction::query()->where('user_id', $user->id);
            if ($transactionIds && count($transactionIds) > 0) {
                $query->whereIn('id', $transactionIds);
            } elseif ($filters !== null) {
                if (isset($filters['date_from'])) {
                    $query->whereDate('transaction_date', '>=', $filters['date_from']);
                }
                if (isset($filters['date_to'])) {
                    $query->whereDate('transaction_date', '<=', $filters['date_to']);
                }
                if (isset($filters['amount_min'])) {
                    $query->where('amount', '>=', $filters['amount_min'] * 100);
                }
                if (isset($filters['amount_max'])) {
                    $query->where('amount', '<=', $filters['amount_max'] * 100);
                }
                if (! empty($filters['category_ids'])) {
                    $query->whereIn('category_id', $filters['category_ids']);
                }
                if (! empty($filters['account_ids'])) {
                    $query->whereIn('account_id', $filters['account_ids']);
                }
                if (! empty($filters['label_ids'])) {
                    $query->whereHas('labels', function ($q) use ($filters) {
                        $q->whereIn('labels.id', $filters['label_ids']);
                    });
                }
            }
            $query->update($updateData);
        }

        if ($hasLabelUpdate) {
            foreach ($transactions as $transaction) {
                $transaction->labels()->sync($labelIds ?? []);
                $transaction->touch();
            }
        }

        return response()->json([
            'message' => 'Transactions updated successfully',
            'count' => $transactions->count(),
        ]);
    }
}
