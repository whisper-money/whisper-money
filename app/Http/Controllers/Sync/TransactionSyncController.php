<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->where('user_id', $request->user()->id);

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $transactions = $query
            ->with('labels:id,name,color')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $transactions,
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? [];
        unset($data['label_ids']);

        // If ID is provided, check if transaction already exists (idempotent create)
        if (isset($data['id'])) {
            $existing = Transaction::query()
                ->where('id', $data['id'])
                ->where('user_id', $request->user()->id)
                ->first();

            if ($existing) {
                // Transaction already exists, return it as success (idempotent)
                return response()->json([
                    'data' => $existing->load('labels:id,name,color'),
                ], 200);
            }
        }

        // Create transaction with provided ID if available
        $transaction = new Transaction([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        // If ID is provided, use it; otherwise Laravel will generate UUID v7
        if (isset($data['id'])) {
            $transaction->id = $data['id'];
            $transaction->exists = false;
        }

        $transaction->save();

        if (! empty($labelIds)) {
            $transaction->labels()->sync($labelIds);
        }

        return response()->json([
            'data' => $transaction->load('labels:id,name,color'),
        ], 201);
    }

    public function update(StoreTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        // Ensure user owns this transaction
        if ($transaction->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validated();
        unset($data['id']); // Don't allow ID changes

        $labelIds = $data['label_ids'] ?? null;
        unset($data['label_ids']);

        $transaction->update($data);

        if ($labelIds !== null) {
            $transaction->labels()->sync($labelIds);
        }

        return response()->json([
            'data' => $transaction->fresh()->load('labels:id,name,color'),
        ]);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        // Ensure user owns this transaction
        if ($transaction->user_id !== request()->user()->id) {
            abort(403, 'Unauthorized');
        }

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully',
        ]);
    }
}
