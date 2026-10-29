<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTransactionRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * Whether to take the transaction back out of the account's balance snapshots. Has no effect on a bank-connected account or a credit card; `balance_updated` in the response says whether they moved. Defaults to `false`.
             */
            'update_balance' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Refuse unknown fields, and one part of a split.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...array_filter(['transaction' => TransactionWriteRules::deletionError($this->transaction())]),
        ])];
    }
}
