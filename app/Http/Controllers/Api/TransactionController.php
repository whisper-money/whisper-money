<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkUpdateTransactionRequest;
use App\Http\Requests\Api\CheckDuplicateTransactionsRequest;
use App\Models\Transaction;
use App\Services\TransactionDuplicateMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Return paginated transactions for the authenticated user with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->transactions()
            ->with('labels');

        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', $accountId)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc');
        }

        $perPage = min(max((int) $request->query('per_page', 100), 1), 100);

        $transactions = $query->simplePaginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Flag which of the given (date, amount, description) tuples already exist
     * on the account. Replaces the old client-side IndexedDB duplicate check.
     * Returns a boolean per input transaction, in order.
     */
    public function checkDuplicates(CheckDuplicateTransactionsRequest $request, TransactionDuplicateMatcher $matcher): JsonResponse
    {
        $validated = $request->validated();
        $account = $request->user()->accounts()->findOrFail($validated['account_id']);

        return response()->json(['duplicates' => $matcher->flag($account, $validated['transactions'])]);
    }

    /**
     * Bulk update transactions (used for decryption migration).
     */
    public function bulkUpdate(BulkUpdateTransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $transactionIds = collect($validated['transactions'])->pluck('id');

        $userTransactionIds = $request->user()
            ->transactions()
            ->whereIn('id', $transactionIds)
            ->pluck('id');

        if ($userTransactionIds->count() !== $transactionIds->count()) {
            abort(403, 'Some transactions do not belong to the authenticated user.');
        }

        $userId = $request->user()->id;

        foreach ($validated['transactions'] as $data) {
            $updateData = collect($data)->except('id')->toArray();

            Transaction::query()
                ->where('id', $data['id'])
                ->where('user_id', $userId)
                ->toBase()
                ->update($updateData);
        }

        return response()->json(['message' => 'Transactions updated successfully.']);
    }
}
