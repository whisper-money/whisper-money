<?php

namespace App\Http\Controllers;

use App\Http\Requests\SplitTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionSplitter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * Splitting a transaction into parts, and putting it back together. The rules
 * and the bookkeeping live in {@see TransactionSplitter}, shared with the MCP
 * tools that do the same thing.
 */
class TransactionSplitController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TransactionSplitter $splitter) {}

    public function store(SplitTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $splits = $this->splitter->split($transaction, $request->validated()['splits']);

        return response()->json(['data' => $splits->values()], 201);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $original = $this->splitter->merge($transaction);

        return response()->json(['data' => $original->load('labels')]);
    }
}
