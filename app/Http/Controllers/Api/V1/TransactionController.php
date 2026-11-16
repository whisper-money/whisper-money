<?php

namespace App\Http\Controllers\Api\V1;

use App\Features\TransactionSplitting;
use App\Http\ApiV1\ApiV1Exception;
use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\DeleteTransactionRequest;
use App\Http\Requests\Api\V1\MergeTransactionSplitsRequest;
use App\Http\Requests\Api\V1\ReplaceTransactionLabelsRequest;
use App\Http\Requests\Api\V1\SearchTransactionsRequest;
use App\Http\Requests\Api\V1\SplitTransactionRequest;
use App\Http\Requests\Api\V1\StoreTransactionRequest;
use App\Http\Requests\Api\V1\UpdateTransactionRequest;
use App\Http\Resources\V1\TransactionResource;
use App\Services\Transactions\TransactionLabeler;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\WrittenTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Pennant\Feature;

class TransactionController extends Controller
{
    public function __construct(private TransactionWriter $writer) {}

    /**
     * List transactions
     *
     * Your transactions in a space, the most recent day first, narrowed by any
     * of the filters as the app's list is: `category_id` takes the whole
     * subtree, a category and `label_ids` sent together match either, and each
     * other filter narrows further.
     *
     * A split transaction is one row. A filter that matches one line returns
     * the whole transaction: `amount` stays the full amount and `splits` holds
     * every line, the ones outside the filter included, so to add up a category
     * read its lines, not `amount`. Labels sit on the transaction, not on a
     * line, so `label_ids` matches every line of it.
     *
     * `query` reads what the server can read: an encrypted description or note
     * never matches, though the row can still match on the counterparty names
     * or any other filter, and reads as `''` in those fields.
     *
     * A parameter this list does not take is a `422`, so a misspelt filter
     * never answers with every transaction.
     */
    public function index(SearchTransactionsRequest $request, Listings $listings): AnonymousResourceCollection
    {
        return $listings->transactions($this->user($request), $this->space($request), $request->validated())->paginate($request);
    }

    /**
     * Retrieve a transaction
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function show(Request $request, string $transaction): TransactionResource
    {
        return new TransactionResource($this->transaction($request, $transaction));
    }

    /**
     * Create a transaction
     *
     * Records a transaction by hand on one of your accounts in the space,
     * bank-connected ones included: a sync never removes it, and nothing
     * deduplicates it against what the bank sends. Automation rules run on it
     * unless it comes with a category, and `label_ids` go on top of what a
     * rule tags it with. The response adds `balance_updated`, whether
     * `update_balance` moved the account's balance snapshots.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $written = $this->writer->create(
            $this->user($request),
            $this->space($request),
            $this->account($request, $request->fields()['account_id']),
            $request->fields(),
            $request->labelIds(),
            $request->boolean('update_balance'),
        );

        return $this->written($written)->response()->setStatusCode(201);
    }

    /**
     * Update a transaction
     *
     * Changes only the fields you send, and also sets or clears the category.
     * The labels are not changed here. The notes, the category and the date can
     * change on any transaction; the rest (description, amount, currency,
     * account and counterparty names) only on one you recorded by hand: on one
     * from a bank or an import, sending them is a `422` and nothing is saved.
     * The category and amount of a split transaction belong to its lines, so
     * they are a `422` under `splits`. A new account brings its currency unless
     * `currency_code` picks another.
     *
     * A description or note encrypted on the device reads as `''`. New text
     * replaces it, and leaves the other field as it is; a blank `notes` on an
     * encrypted note is a `422`, so writing back what was read erases nothing.
     *
     * The response adds `balance_updated`, whether `update_balance` moved the
     * account's balance snapshots. It only moves them for one you recorded by
     * hand.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function update(UpdateTransactionRequest $request, string $transaction): TransactionResource
    {
        return $this->written($this->writer->update(
            $request->transaction(),
            $request->changes(),
            $request->boolean('update_balance'),
        ));
    }

    /**
     * Delete a transaction
     *
     * Deletes a transaction, one that came from a bank or an import included:
     * as in the app, the next sync does not bring it back. Re-uploading a file
     * that holds it does.
     *
     * With `update_balance` it also takes the transaction back out of the
     * account's balance snapshots, and `balance_updated` says whether they
     * moved: not on a bank-connected account or a credit card, and not when
     * there is no rate to convert the amount with.
     *
     * Unlike an edit, this goes by the account, not the transaction: one that
     * came from an import onto a manual account does move the balance when
     * deleted, as in the app, though editing it never does.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function destroy(DeleteTransactionRequest $request, string $transaction): JsonResponse
    {
        $model = $request->transaction();

        return response()->json([
            'id' => $model->id,
            'deleted' => true,
            'balance_updated' => $this->writer->delete($model, $request->boolean('update_balance')),
        ]);
    }

    /**
     * Replace the labels of a transaction
     *
     * Sets the transaction's labels to exactly `label_ids`: a label it had
     * that is not in the list is removed, and an empty list removes them all.
     * Works on any transaction you own, including one from a bank or an import
     * and a split one, whose lines share its labels. Labelling is also how a
     * transaction counts towards a savings goal.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function labels(ReplaceTransactionLabelsRequest $request, string $transaction, TransactionLabeler $labeler): TransactionResource
    {
        return new TransactionResource($labeler->replace($request->transaction(), $request->labelIds()));
    }

    /**
     * Split a transaction
     *
     * Files a transaction under 2 to 20 lines, each with its own amount and
     * category, replacing the lines it had. Works on bank and imported
     * transactions too, and changes neither the transaction's amount, date and
     * account nor its balance. Answers the transaction, with the lines nested
     * under `splits`.
     *
     * The lines must add up to the transaction's amount exactly, each with its
     * sign and none zero, and their categories must all be expense or all be
     * income. Anything else is a `422` under `splits`.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function split(SplitTransactionRequest $request, string $transaction): TransactionResource
    {
        if (! Feature::for($this->user($request))->active(TransactionSplitting::class)) {
            throw new ApiV1Exception(403, 'splitting_disabled', 'Creating or replacing transaction splits is currently disabled. Existing splits may still be merged back.');
        }

        return new TransactionResource($this->writer->split($request->transaction(), $request->lines()));
    }

    /**
     * Merge a split back
     *
     * Puts a split transaction back in one piece, removing its lines. It takes
     * `category_id`, an expense or income category of yours in the space,
     * because a split transaction holds no category of its own to go back to.
     * One that is not split answers `422` under `transaction`, and a category
     * of another type under `splits`.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function mergeSplits(MergeTransactionSplitsRequest $request, string $transaction): TransactionResource
    {
        return new TransactionResource($this->writer->mergeSplits($request->transaction(), $request->categoryId()));
    }

    private function written(WrittenTransaction $written): TransactionResource
    {
        return (new TransactionResource($written->transaction))->additional([
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
