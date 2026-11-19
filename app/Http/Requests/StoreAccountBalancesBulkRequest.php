<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Rules\StorableDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Many daily balances for one account in a single request, for an import that
 * carries a balance per day (a broker export can hold years of them).
 *
 * Refuses a credit card, which has no balance. A connected account is taken,
 * as the single-balance endpoint takes it: a sync only rewrites today and the
 * days still to come, so the history the "Import balances" drawer writes on
 * one stays. The broker import leaves connected accounts out on its own.
 */
class StoreAccountBalancesBulkRequest extends FormRequest
{
    /** The most balances one request may carry. */
    private const int MAX_BALANCES = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'balances' => ['required', 'array', 'list', 'max:'.self::MAX_BALANCES],
            'balances.*.balance_date' => ['required', 'date', new StorableDate],
            'balances.*.balance' => ['required', 'integer'],
            'balances.*.invested_amount' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');

            // Someone else's account is the controller's 403 to give, so its
            // type is not revealed by a validation error first.
            if (! $account instanceof Account || $this->user()->cannot('update', $account)) {
                return;
            }

            if (! $account->type->hasBalance()) {
                $validator->errors()->add('balances', AccountType::NO_BALANCE_MESSAGE);
            }
        }];
    }
}
