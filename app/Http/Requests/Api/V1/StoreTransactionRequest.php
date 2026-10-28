<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesTransactionWrites;
use App\Models\Label;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    use ValidatesTransactionWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->transactionFields(TransactionWriteRules::createRules()),
            /**
             * The ids of the labels to tag the transaction with.
             */
            'label_ids' => ['sometimes', 'array'],
            /**
             * @format uuid
             */
            'label_ids.*' => ['string', 'distinct', $this->inSpace(Label::class, 'a label')],
        ];
    }

    /**
     * Refuse unknown fields.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
