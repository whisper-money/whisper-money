<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkUpdateTransactionRequest;
use App\Models\Transaction;
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

        if ($request->query('encrypted') === 'true') {
            $query->where(fn ($q) => $q->whereNotNull('description_iv')->orWhereNotNull('notes_iv'));
        }

        $transactions = $query->simplePaginate(100);

        return response()->json($transactions);
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

        foreach ($validated['transactions'] as $data) {
            $updateData = collect($data)->except('id')->toArray();

            Transaction::query()
                ->where('id', $data['id'])
                ->toBase()
                ->update($updateData);
        }

        return response()->json(['message' => 'Transactions updated successfully.']);
    }
}
