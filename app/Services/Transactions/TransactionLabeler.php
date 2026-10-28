<?php

namespace App\Services\Transactions;

use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Transaction;

/**
 * Changing the labels of a transaction, shared by the `label_transaction` MCP
 * tool and `PUT /api/v1/transactions/{id}/labels`. The label ids arrive
 * already checked to be in the transaction's space.
 */
class TransactionLabeler
{
    /**
     * Attach $add and detach $remove, leaving every other label in place.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function change(Transaction $transaction, array $add, array $remove): Transaction
    {
        if ($add !== []) {
            $transaction->labels()->syncWithoutDetaching($add);
        }

        if ($remove !== []) {
            $transaction->labels()->detach($remove);
        }

        return $this->reassignBudgets($transaction);
    }

    /**
     * Make $labelIds the transaction's only labels; an empty list clears them.
     *
     * @param  list<string>  $labelIds
     */
    public function replace(Transaction $transaction, array $labelIds): Transaction
    {
        $transaction->labels()->sync($labelIds);

        return $this->reassignBudgets($transaction);
    }

    /**
     * Pivot writes fire no model event, so nothing would re-derive which
     * budgets now track this transaction by label. Silently: relabelling an
     * existing transaction is not new spending, and removing a label would
     * otherwise announce a months-old expense as new in the catch-all budget.
     */
    private function reassignBudgets(Transaction $transaction): Transaction
    {
        ReassignTransactionsToBudgets::dispatch([$transaction->id], notify: false);

        return $transaction->refresh();
    }
}
