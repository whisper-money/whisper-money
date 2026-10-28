<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAccountWrites;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    use ValidatesAccountWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->accountRules();
        $fields = $rules->createRules($this->requestedType());

        return [
            /**
             * The account's name.
             *
             * @example Main checking
             */
            'name' => $fields['name'],
            /**
             * The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only.
             *
             * @example checking
             */
            'type' => $rules->typeRule(required: true)['type'],
            /**
             * The ISO 4217 currency the account is held in. Your first account also sets the currency your whole account is reported in, so it must be one of the primary currencies.
             *
             * @example EUR
             */
            'currency_code' => $fields['currency_code'],
            /**
             * The id of the bank the account is held at, for its name and logo. It does not connect anything.
             */
            'bank_id' => $fields['bank_id'],
            /**
             * The balance today, in minor units. On a loan or a property it is also where the generated balance history ends. Refused on a credit card, which takes a `credit_limit` instead.
             *
             * @example 125000
             */
            'balance' => $fields['balance'],
            /**
             * `loan` only: id of the `real_estate` account this loan is the mortgage of. The property must have its details set and not be linked to another loan yet.
             *
             * @format uuid
             */
            'linked_real_estate_account_id' => $fields['linked_real_estate_account_id'] ?? ['exclude'],
            ...$this->detailFields($fields),
        ];
    }

    /**
     * Refuse the fields that would make a bank-connected account, and a
     * balance on a credit card.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rules = $this->accountRules();
            $errors = [
                ...$rules->connectionFieldErrors($this->all()),
                ...$rules->creditCardBalanceErrors($this->all(), $this->requestedType()),
            ];

            $this->addErrors($validator, $errors);
        }];
    }
}
