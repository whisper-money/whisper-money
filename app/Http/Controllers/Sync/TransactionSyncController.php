<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
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
        $query = $request->user()->activeSpace()->transactions();

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $transactions = $query
            ->with('labels')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $transactions,
        ]);
    }
}
