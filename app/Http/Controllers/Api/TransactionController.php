<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkUpdateTransactionRequest;
use App\Http\Requests\Api\CheckDuplicateTransactionsRequest;
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
     *
     * Matching mirrors the previous frontend logic: same day, exact amount (both
     * in integer cents), and case-insensitive description with collapsed
     * whitespace. Returns a boolean per input transaction, in order.
     */
    public function checkDuplicates(CheckDuplicateTransactionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $account = $request->user()->accounts()->findOrFail($validated['account_id']);
        $incoming = $validated['transactions'];

        $dates = array_map(fn (array $t): string => substr($t['transaction_date'], 0, 10), $incoming);

        $existing = $account->transactions()
            ->whereBetween('transaction_date', [min($dates), max($dates)])
            ->get(['transaction_date', 'amount', 'description']);

        $seen = [];
        foreach ($existing as $transaction) {
            $seen[$this->duplicateKey(
                $transaction->transaction_date->format('Y-m-d'),
                (int) $transaction->amount,
                $transaction->description,
            )] = true;
        }

        $duplicates = array_map(
            fn (array $t): bool => isset($seen[$this->duplicateKey(
                substr($t['transaction_date'], 0, 10),
                (int) $t['amount'],
                $t['description'],
            )]),
            $incoming,
        );

        return response()->json(['duplicates' => $duplicates]);
    }

    private function duplicateKey(string $date, int $amount, string $description): string
    {
        // Collapse every Unicode whitespace run (matching JS \s, which includes
        // the non-breaking spaces common in bank statements) to a single space,
        // then trim. PHP's default \s is ASCII-only, so without this an existing
        // "Coffee Shop" and an imported "Coffee Shop" would not be seen as
        // the same row, unlike the old client-side check.
        $normalized = trim((string) preg_replace(
            '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u',
            ' ',
            mb_strtolower($description),
        ));

        return $date.'|'.$amount.'|'.$normalized;
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
