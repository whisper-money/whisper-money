<?php

namespace App\Http\Controllers;

use App\Features\TransactionSplitting;
use App\Http\Requests\SplitTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionSplitter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Laravel\Pennant\Feature;

/**
 * Splitting a transaction into parts, and putting it back together. The rules
 * and the bookkeeping live in {@see TransactionSplitter}. Splitting is behind
 * the TransactionSplitting flag; merging never is, so a split made while it
 * was on can always be undone.
 */
class TransactionSplitController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TransactionSplitter $splitter) {}

    public function store(SplitTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        abort_unless(
            Feature::for($request->user())->active(TransactionSplitting::class),
            403,
            'Creating or replacing transaction splits is currently disabled.',
        );

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
