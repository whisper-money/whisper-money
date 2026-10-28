<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAccount;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountBalanceRequest extends FormRequest
{
    use ResolvesRouteAccount;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = app(AccountBalanceRecorder::class)->rules();

        return [
            /**
             * The balance, in the account currency's minor units.
             *
             * @example 250000
             */
            'balance' => $rules['balance'],
            /**
             * The day the balance is for. Defaults to today.
             *
             * @format date
             *
             * @example 2026-01-31
             */
            'balance_date' => $rules['balance_date'],
            /**
             * How much of the balance is money put in rather than gains, in minor units, for investment accounts. Left out, the one already recorded for that day stays.
             */
            'invested_amount' => $rules['invested_amount'],
        ];
    }

    /**
     * Refuse a bank-connected account and a credit card, which take no
     * manual balance.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (app(AccountBalanceRecorder::class)->unwritableErrors($this->account(), 'account') as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }
}
