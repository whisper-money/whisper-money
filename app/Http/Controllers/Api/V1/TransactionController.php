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
use App\Services\TransactionSplitter;
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
     * A transaction that was split is not listed: its parts are, each with the
     * same `split_parent_id`, and each matches a filter on its own category and
     * labels. A transaction still split the old way is one row whose `splits`
     * holds its lines: a filter that matches one line returns the whole row,
     * `amount` stays the full amount and `splits` holds every line, the ones
     * outside the filter included, so to add up a category read its lines, not
     * `amount`. Its labels sit on the row, so `label_ids` matches every line.
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
     * The labels are not changed here. The notes and the category can change on
     * any transaction, and the date on any but one part of a split; the rest
     * (description, amount, currency, account and counterparty names) only on
     * one you recorded by hand that is not one part of a split: on any other,
     * sending them is a `422` and nothing is saved. The category and amount of
     * a transaction still split into the old lines belong to its lines, so
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
     * that holds it does. One part of a split cannot be deleted on its own and
     * answers `422` under `transaction`: merge the split back first.
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
     * Works on any transaction you own, including one from a bank or an import,
     * one part of a split, and one still split into the old lines, whose lines
     * share its labels. Labelling is also how a transaction counts towards a
     * savings goal.
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
     * Replaces a transaction that covered several things with 2 to 20 parts,
     * each with its own amount, optional category and labels. Works on bank and
     * imported transactions too, and moves no balance. The parts must add up to
     * the original exactly and all move money the same way; anything else is a
     * `422` under `splits`. From then on the parts are what exists: the original
     * is out of every list and every total, and each part carries a
     * `split_parent_id` shared with its siblings. A part cannot be split again,
     * deleted on its own, or have its amount, date, account or other core
     * fields changed; categorizing, labelling and editing its notes work as
     * usual. Splitting a part again, or a transaction still split into the old
     * lines (`splits`, which it keeps until it is converted), answers `422`
     * under `transaction`, and the original's id answers `404` once it is
     * split. While splitting is switched off for the account, `403` with
     * `splitting_disabled`. Answers `201` with the parts, in the order they were
     * sent.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function split(SplitTransactionRequest $request, string $transaction, TransactionSplitter $splitter): JsonResponse
    {
        if (! Feature::for($this->user($request))->active(TransactionSplitting::class)) {
            throw new ApiV1Exception(403, 'splitting_disabled', 'Creating transaction splits is currently disabled. Existing splits may still be merged back.');
        }

        $parts = $splitter->split($request->transaction(), $request->splits());

        return TransactionResource::collection($parts)->response()->setStatusCode(201);
    }

    /**
     * Merge a split back
     *
     * Puts back the transaction a split came from, with the category it had
     * before, and deletes every part along with the category, labels and notes
     * set on them. Pass the id of any one of the parts, and no body; one that is
     * not part of a split answers `422` under `transaction`, and the original's
     * own id answers `404`: it is out of every list until the merge. Merging
     * stays open while splitting is switched off. Answers the original
     * transaction, under its id from before the split.
     *
     * @param  string  $transaction  The id of any part of the split.
     */
    public function mergeSplits(MergeTransactionSplitsRequest $request, string $transaction, TransactionSplitter $splitter): TransactionResource
    {
        return new TransactionResource($splitter->merge($request->transaction()));
    }

    private function written(WrittenTransaction $written): TransactionResource
    {
        return (new TransactionResource($written->transaction))->additional([
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
