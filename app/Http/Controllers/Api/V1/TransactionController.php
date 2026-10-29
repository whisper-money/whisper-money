<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\DeleteTransactionRequest;
use App\Http\Requests\Api\V1\SearchTransactionsRequest;
use App\Http\Requests\Api\V1\StoreTransactionRequest;
use App\Http\Requests\Api\V1\UpdateTransactionRequest;
use App\Http\Resources\V1\TransactionResource;
use App\Services\Transactions\TransactionSearch;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\WrittenTransaction;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Transactions', 'A transaction is money that moved in or out of an account: synced from the bank, imported from a file or recorded by hand. Whisper Money only keeps the books: recording a transaction moves no money. Amounts are signed, in the minor units of the transaction\'s own currency: negative for an expense, positive for income.', weight: 40)]
class TransactionController extends Controller
{
    public function __construct(private TransactionWriter $writer) {}

    /**
     * List transactions
     *
     * Your transactions in a space, the most recent day first, narrowed by
     * any of the filters. As in the app, another member's transactions are
     * not listed. A transaction that was split is not listed: its parts are, each with the
     * same `split_parent_id`. To find recurring charges, such as
     * subscriptions, search them and group the results by merchant and
     * cadence.
     */
    #[ScopedToSpace]
    #[Paginated(TransactionResource::class)]
    public function index(SearchTransactionsRequest $request, TransactionSearch $search): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            $search->query($this->user($request), $this->space($request), $request->validated())->with(TransactionResource::Relations),
            $request,
            [['transaction_date', 'desc'], ['id', 'desc']],
            TransactionResource::class,
        );
    }

    /**
     * Retrieve a transaction
     *
     * @param  string  $transaction  The transaction's id.
     */
    #[ScopedToSpace]
    public function show(Request $request, string $transaction): TransactionResource
    {
        return new TransactionResource($this->transaction($request, $transaction));
    }

    /**
     * Create a transaction
     *
     * Records a transaction by hand on one of your accounts in the space,
     * bank-connected ones included: a sync never removes it. Nothing
     * deduplicates it against what the bank sends, so add only what the bank
     * will not sync, such as a cash payment or a transfer you made elsewhere.
     * The response adds `balance_updated`, whether
     * `update_balance` moved the account's balance snapshots.
     */
    #[ScopedToSpace]
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $space = $this->space($request);

        $written = $this->writer->create(
            $this->user($request),
            $space,
            $this->account($request, $validated['account_id']),
            $validated,
            $validated['label_ids'] ?? [],
            $request->boolean('update_balance'),
        );

        return $this->written($written)->response()->setStatusCode(201);
    }

    /**
     * Update a transaction
     *
     * Changes only the fields you send, and also sets or clears the category.
     * The labels are not changed here. The notes and the category can be
     * changed on any transaction, and the date on any but one part of a
     * split. The rest (description, amount, currency, account and
     * counterparty names) only on one you recorded by hand that is not one
     * part of a split: on any other, sending them is a `422` and nothing is
     * saved. The response
     * adds `balance_updated`, whether `update_balance` moved the account's
     * balance snapshots.
     *
     * @param  string  $transaction  The transaction's id.
     */
    #[ScopedToSpace]
    public function update(UpdateTransactionRequest $request, string $transaction): TransactionResource
    {
        return $this->written($this->writer->update(
            $request->transaction(),
            $request->validated(),
            $request->boolean('update_balance'),
        ));
    }

    /**
     * Delete a transaction
     *
     * Deletes a transaction, one that came from a bank or an import included:
     * as in the app, the next sync does not bring it back. One part of a split
     * cannot be deleted on its own and answers `422` with the error under
     * `transaction`.
     *
     * @param  string  $transaction  The transaction's id.
     */
    #[ScopedToSpace]
    public function destroy(DeleteTransactionRequest $request, string $transaction): JsonResponse
    {
        $model = $request->transaction();

        return response()->json([
            /**
             * The deleted transaction's id.
             *
             * @format uuid
             */
            'id' => $model->id,
            /**
             * Always `true`.
             */
            'deleted' => true,
            /**
             * Whether `update_balance` took the transaction back out of the account's balance snapshots.
             */
            'balance_updated' => $this->writer->delete($model, $request->boolean('update_balance')),
        ]);
    }

    private function written(WrittenTransaction $written): TransactionResource
    {
        return (new TransactionResource($written->transaction))->additional([
            /**
             * Whether `update_balance` moved the account's balance snapshots.
             */
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
