<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Rules\StorableDate;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Many daily balances for one account in a single request, for an import that
 * carries a balance per day (a broker export can hold years of them).
 *
 * Refuses the accounts a manual snapshot does not belong on: a credit card,
 * which has no balance, and a connected account, whose next sync would
 * overwrite every day written here.
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

            foreach (AccountBalanceRecorder::unwritableErrors($account, 'balances') as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        }];
    }
}
