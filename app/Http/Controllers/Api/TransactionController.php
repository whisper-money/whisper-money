<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkUpdateTransactionRequest;
use App\Http\Requests\Api\CheckDuplicateTransactionsRequest;
use App\Http\Requests\IndexTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionCsvExporter;
use App\Services\TransactionDuplicateMatcher;
use App\Services\TransactionFilterSummary;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Return paginated transactions for the authenticated user with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->transactions()
            ->with(['labels', 'splits.category']);

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
        $transactions->getCollection()->each->append(['is_split', 'split_count']);

        return response()->json($transactions);
    }

    /**
     * One of the user's transactions in the shape the transactions table
     * holds its rows (its split lines included), so the edit dialog can open
     * on it from anywhere that only knows its id (a row of a dashboard
     * widget). Only a transaction the user may edit on the transactions page,
     * the same rule as its update and delete.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $transaction->load(['account.bank', 'category', 'labels', 'categorizedByRule:id,origin', 'splits.category'])
            ->append(['ai_categorized', 'is_split', 'split_count']);

        Transaction::loadSplitSiblings(collect([$transaction]));

        return response()
            ->json(['data' => $transaction])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * How many rows exporting the transactions page's filters writes (a split
     * transaction writes one per kept line), for the export dialog to say how
     * big "all matching" is before downloading it.
     */
    public function count(IndexTransactionRequest $request, TransactionCsvExporter $exporter): JsonResponse
    {
        $filters = $request->filters();

        $count = $exporter->countRows(
            Transaction::query()->where('user_id', $request->user()->id)->applyFilters($filters),
            $filters,
        );

        return response()->json(['count' => $count]);
    }

    /**
     * How many rows the transactions list shows for these filters, for a
     * filter set that is not applied yet. Not {@see self::count()}: a split
     * transaction is one row here however many of its lines match. It reads
     * no total and no rate, so a count on every pause never reaches the rates
     * provider.
     */
    public function listedCount(IndexTransactionRequest $request, TransactionFilterSummary $summary): JsonResponse
    {
        return response()
            ->json(['count' => $summary->count($request->filters())])
            ->header('Cache-Control', 'no-store, private');
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
