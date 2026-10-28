<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use App\Models\Category;

/**
 * What the transaction create and update requests share: the
 * TransactionWriteRules the MCP tools validate with too, the account and
 * category they point at, checked to be in the request's space, and the
 * balance switch.
 */
trait ValidatesTransactionWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the TransactionWriteRules for the write
     * @return array<string, array<mixed>>
     */
    protected function transactionFields(array $rules): array
    {
        return [
            /**
             * The id of the account the transaction is on. Any account of the space, bank-connected ones included: a sync never removes a transaction you added by hand.
             *
             * @format uuid
             *
             * @example 0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01
             */
            'account_id' => [...$rules['account_id'] ?? ['sometimes', 'string'], $this->inSpace(Account::class, 'an account')],
            /**
             * What the transaction was for.
             *
             * @example Groceries at the market
             */
            'description' => $rules['description'],
            /**
             * The signed amount, in the minor units of `currency_code`: negative for an expense, positive for income.
             *
             * @example -4250
             */
            'amount' => $rules['amount'],
            /**
             * The day the transaction happened.
             *
             * @format date
             *
             * @example 2026-01-31
             */
            'transaction_date' => $rules['transaction_date'],
            /**
             * The ISO 4217 currency the amount is in. Defaults to the account's.
             *
             * @example EUR
             */
            'currency_code' => $rules['currency_code'],
            /**
             * The id of the category, or `null` for none. Setting it marks the category as assigned by you, so the automatic categorization leaves it alone.
             *
             * @format uuid
             */
            'category_id' => ['sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
            /**
             * Who was paid, or `null`.
             *
             * @example Fresh Market
             */
            'creditor_name' => $rules['creditor_name'],
            /**
             * Who paid, or `null`.
             */
            'debtor_name' => $rules['debtor_name'],
            /**
             * Free-text notes, or `null`.
             */
            'notes' => $rules['notes'],
            /**
             * Whether to move the account's balance snapshots by what this write changes. Has no effect on a bank-connected account, whose balances come from the bank, or on a credit card, which has a credit limit instead of a balance; `balance_updated` in the response says whether they moved. Defaults to `false`.
             */
            'update_balance' => ['sometimes', 'boolean'],
        ];
    }
}
