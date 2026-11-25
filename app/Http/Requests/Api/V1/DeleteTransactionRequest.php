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
            'update_balance' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Refuse unknown fields, and one part of a split. The writer checks the
     * part again under the row lock.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return $this->refusingUnknownFieldsAnd(fn (): array => [
            'transaction' => TransactionWriteRules::deletionError($this->transaction()),
        ]);
    }
}
