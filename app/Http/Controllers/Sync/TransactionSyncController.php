<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionSyncController extends Controller
{
    /**
     * Fetch transactions for client-side IndexedDB sync.
     * Supports delta sync via 'since' parameter.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $query = Transaction::query()
            ->where('user_id', $request->user()->id);

        if ($validated['since'] ?? null) {
            $query->where('updated_at', '>', $validated['since']);
        }

        $transactions = $query
            ->with('labels')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('updated_at', 'desc')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $transactions,
            'has_more' => $transactions->count() === 500,
        ]);
    }
}
